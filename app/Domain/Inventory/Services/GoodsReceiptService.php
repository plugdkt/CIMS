<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidGoodsReceiptStateException;
use App\Domain\Shared\UnitConverter;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * FR-RC-01..06. Confirming a DRAFT GRN is the only place `containers` get created
 * from a receipt, and it writes exactly one `RECEIVE` ledger row per container
 * (FR-RC-05) through `LedgerService` — this class never touches `stock_ledger`
 * itself.
 */
final class GoodsReceiptService
{
    private const SCALE = 6;

    public function __construct(
        private readonly UnitConverter $converter,
        private readonly LedgerService $ledgerService,
    ) {
    }

    /**
     * FR-RC-03: a line's total is expressed in the item's own base unit — which
     * may differ from the unit the line was received in (`$lineUnit`), so this
     * always routes through the dimension's canonical base as an intermediate
     * step (mirrors T-006's `UnitConverter`), crossing dimensions via the item's
     * density when the receiving unit and the item's base unit aren't the same
     * kind of measurement (e.g. received by volume, tracked by mass).
     *
     * @param  numeric-string  $containerCount
     * @param  numeric-string  $qtyPerContainer
     * @return numeric-string
     */
    public function calculateLineTotalBase(
        Item $item,
        Unit $lineUnit,
        string $containerCount,
        string $qtyPerContainer,
    ): string {
        $qtyInLineUnit = bcmul($containerCount, $qtyPerContainer, self::SCALE);

        return $this->converter->toItemBase($item, $lineUnit, $qtyInLineUnit);
    }

    /**
     * FR-RC-02/03/05: creates `container_count` containers per line (barcode
     * derived from the GRN's own unique doc_no, so uniqueness is guaranteed
     * without a collision check), inheriting lot_no/expiry_date/location from
     * the line, then writes one RECEIVE ledger row per container.
     */
    public function confirm(GoodsReceipt $goodsReceipt, int $confirmedBy): GoodsReceipt
    {
        if ($goodsReceipt->status !== 'DRAFT') {
            throw new InvalidGoodsReceiptStateException('ยืนยันได้เฉพาะ GRN ที่ยังเป็นสถานะร่าง (DRAFT) เท่านั้น');
        }

        $lines = $goodsReceipt->items;
        if ($lines->isEmpty()) {
            throw new InvalidGoodsReceiptStateException('ต้องมีอย่างน้อย 1 รายการก่อนยืนยัน');
        }

        return DB::transaction(function () use ($goodsReceipt, $confirmedBy, $lines) {
            foreach ($lines as $line) {
                $qtyPerContainerBase = bcdiv($line->qty_total_base, (string) $line->container_count, self::SCALE);

                for ($seq = 1; $seq <= $line->container_count; $seq++) {
                    $container = Container::create([
                        'barcode' => sprintf('%s-%02d-%03d', $goodsReceipt->doc_no, $line->line_no, $seq),
                        'item_id' => $line->item_id,
                        'location_id' => $line->location_id,
                        'lot_no' => $line->lot_no,
                        'received_at' => $goodsReceipt->receipt_date,
                        'expiry_date' => $line->expiry_date,
                        'initial_qty_base' => $qtyPerContainerBase,
                        'remaining_qty_base' => '0.000000',
                        'unit_price' => $line->unit_price,
                        'status' => 'SEALED',
                    ]);

                    $this->ledgerService->receive($container->id, $qtyPerContainerBase, new LedgerEntryData(
                        displayUnitId: $line->unit_id,
                        createdBy: $confirmedBy,
                        refType: 'GRN',
                        refId: $goodsReceipt->id,
                        refDocNo: $goodsReceipt->doc_no,
                    ));
                }
            }

            $goodsReceipt->status = 'CONFIRMED';
            $goodsReceipt->confirmed_at = now();
            $goodsReceipt->save();
            $goodsReceipt->refresh();

            return $goodsReceipt;
        });
    }

    /**
     * Only a DRAFT can be cancelled — once CONFIRMED, containers and ledger rows
     * already exist and ledger rows can never be edited or removed (AGENT RULE
     * #6), so "cancel after confirm" would need a deliberate reversal workflow
     * this task doesn't build. See CLAUDE.md.
     */
    public function cancel(GoodsReceipt $goodsReceipt): GoodsReceipt
    {
        if ($goodsReceipt->status !== 'DRAFT') {
            throw new InvalidGoodsReceiptStateException('ยกเลิกได้เฉพาะ GRN ที่ยังเป็นสถานะร่าง (DRAFT) เท่านั้น');
        }

        $goodsReceipt->status = 'CANCELLED';
        $goodsReceipt->save();

        return $goodsReceipt;
    }

    /**
     * @param  numeric-string  $qtyPerContainer
     * @param  array<string, mixed>  $extra  lot_no/expiry_date/location_id/unit_price
     * @return GoodsReceiptItem the created line, with qty_total_base already computed
     */
    public function addLine(
        GoodsReceipt $goodsReceipt,
        Item $item,
        Unit $unit,
        int $containerCount,
        string $qtyPerContainer,
        array $extra = [],
    ): GoodsReceiptItem {
        if ($goodsReceipt->status !== 'DRAFT') {
            throw new InvalidGoodsReceiptStateException('เพิ่มรายการได้เฉพาะ GRN ที่ยังเป็นสถานะร่าง (DRAFT) เท่านั้น');
        }

        $nextLineNo = 1 + (int) $goodsReceipt->items()->max('line_no');
        $qtyTotalBase = $this->calculateLineTotalBase($item, $unit, (string) $containerCount, $qtyPerContainer);

        return $goodsReceipt->items()->create(array_merge([
            'line_no' => $nextLineNo,
            'item_id' => $item->id,
            'container_count' => $containerCount,
            'qty_per_container' => $qtyPerContainer,
            'unit_id' => $unit->id,
            'qty_total_base' => $qtyTotalBase,
        ], $extra));
    }
}
