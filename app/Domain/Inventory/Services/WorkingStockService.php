<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Shared\UnitConverter;
use App\Models\Container;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Turns stock into physical, barcoded working-stock containers and writes the matching
 * `stock_ledger` RECEIVE rows. Extracted from the old direct stock-in form: since
 * 2026-09-30 the only caller is {@see ImsService::transferToWorkingStock()}.
 */
final class WorkingStockService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * @param  numeric-string  $qtyPerContainer  (CONTAINER mode) or the whole quantity (BULK mode)
     * @return list<Container>
     */
    public function receive(
        Item $item,
        Unit $unit,
        Location $location,
        string $trackingType,
        string $qtyPerContainer,
        int $containerCount,
        ?string $lotNo,
        ?string $expiryDate,
        string $remark,
        int $userId,
        ?int $imsLotId,
        ?string $unitPrice,
    ): array {
        /** @var numeric-string $perBase */
        $perBase = $this->converter->toItemBase($item, $unit, $qtyPerContainer);
        $bulk = $trackingType !== 'CONTAINER';
        $count = $bulk ? 1 : $containerCount;

        $containers = [];
        for ($seq = 1; $seq <= $count; $seq++) {
            $barcode = sprintf('%s-%s-%04d-%s', $bulk ? 'BLK' : 'WS', now()->format('ymd'), $item->id, Str::upper(Str::random(4)));

            $container = Container::create([
                'barcode' => $barcode,
                'item_id' => $item->id,
                'ims_lot_id' => $imsLotId,
                'location_id' => $location->id,
                'lot_no' => $lotNo,
                'received_at' => now()->toDateString(),
                'expiry_date' => $expiryDate,
                'unit_price' => $unitPrice,
                'initial_qty_base' => $perBase,
                'remaining_qty_base' => '0.000000',
                'status' => $bulk ? 'IN_USE' : 'SEALED',
                'opened_at' => $bulk ? now()->toDateString() : null,
            ]);

            $this->ledger->receive(
                $container->id,
                $perBase,
                new LedgerEntryData(
                    displayUnitId: $unit->id,
                    createdBy: $userId,
                    refType: 'WORKING_STOCK',
                    refId: $container->id,
                    refDocNo: $barcode,
                    remark: $remark,
                )
            );

            $containers[] = $container;
        }

        return $containers;
    }
}
