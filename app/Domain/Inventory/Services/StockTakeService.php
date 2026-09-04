<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidStockTakeException;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Models\Container;
use App\Models\Lab;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FR-ST-02..04: a stock take round — generate lines from a lab's active containers,
 * record counts (FR-ST-03's mobile scan target), then approve, which is the only path
 * that writes `ADJUST_IN`/`ADJUST_OUT` ledger rows (FR-ST-04, through `LedgerService::
 * adjust()` — still the only writer of `stock_ledger`).
 *
 * "Active" containers (FR-ST-02) means anything that still physically exists and might
 * hold material: SEALED, IN_USE, QUARANTINE. EMPTY containers have a trivially known
 * count (zero) and DISPOSED ones no longer physically exist, so neither needs counting.
 * A container with no `location_id` can't be attributed to any lab and is never
 * included — spec doesn't cover that case, since `location_id` is nullable everywhere
 * (same gap T-016 already noted for BR-10).
 */
final class StockTakeService
{
    private const SCALE = 6;
    private const ELIGIBLE_CONTAINER_STATUSES = ['SEALED', 'IN_USE', 'QUARANTINE'];

    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {
    }

    public function create(Lab $lab, string $countDate, User $creator): StockTake
    {
        return DB::transaction(function () use ($lab, $countDate, $creator) {
            $stockTake = StockTake::create([
                'doc_no' => $this->documentNumbers->next('STK'),
                'lab_id' => $lab->id,
                'count_date' => $countDate,
                'status' => 'COUNTING',
                'created_by' => $creator->id,
            ]);

            $containers = Container::whereHas('location', fn ($q) => $q->where('lab_id', $lab->id))
                ->whereIn('status', self::ELIGIBLE_CONTAINER_STATUSES)
                ->get();

            foreach ($containers as $container) {
                StockTakeLine::create([
                    'stock_take_id' => $stockTake->id,
                    'container_id' => $container->id,
                    'system_qty_base' => $container->remaining_qty_base,
                ]);
            }

            return $stockTake;
        });
    }

    /**
     * FR-ST-03: scan barcode → enter the actual counted amount (already in the item's
     * own base unit — spec's literal wording never asks for a unit switch here, unlike
     * issuing/returning) → save.
     *
     * @param  numeric-string  $countedQtyBase
     */
    public function recordCount(StockTakeLine $line, string $countedQtyBase, User $counter, ?string $reason = null): StockTakeLine
    {
        $stockTake = $line->stockTake()->firstOrFail();
        if ($stockTake->status !== 'COUNTING') {
            throw new InvalidStockTakeException('บันทึกยอดนับได้เฉพาะรอบที่อยู่ระหว่างการนับเท่านั้น');
        }

        $line->counted_qty_base = $countedQtyBase;
        $line->diff_base = bcsub($countedQtyBase, $line->system_qty_base, self::SCALE);
        $line->reason = $reason;
        $line->counted_by = $counter->id;
        $line->counted_at = now();
        $line->save();

        return $line;
    }

    public function submitForApproval(StockTake $stockTake): StockTake
    {
        if ($stockTake->status !== 'COUNTING') {
            throw new InvalidStockTakeException('ส่งขออนุมัติได้เฉพาะรอบที่อยู่ระหว่างการนับเท่านั้น');
        }
        if ($stockTake->lines()->whereNull('counted_qty_base')->exists()) {
            throw new InvalidStockTakeException('ต้องนับให้ครบทุกภาชนะก่อนส่งขออนุมัติ');
        }

        $stockTake->status = 'PENDING_APPROVAL';
        $stockTake->save();

        return $stockTake;
    }

    /**
     * FR-ST-04 / BR-06: one ADJUST_IN/ADJUST_OUT row per line with a real difference.
     * BR-06 requires the approver to differ from the row's `created_by` — checked for
     * every affected line upfront, so an approval is all-or-nothing, never partially
     * applied because one line happened to have been counted by the approver.
     */
    public function approve(StockTake $stockTake, User $approver): StockTake
    {
        if ($stockTake->status !== 'PENDING_APPROVAL') {
            throw new InvalidStockTakeException('อนุมัติได้เฉพาะรอบที่ส่งขออนุมัติแล้วเท่านั้น');
        }

        $diffLines = $stockTake->lines()->whereNotNull('diff_base')->where('diff_base', '!=', 0)->get();

        if ($diffLines->contains(fn (StockTakeLine $line) => $line->counted_by === $approver->id)) {
            throw new InvalidStockTakeException('ผู้อนุมัติต้องไม่ใช่ผู้ที่นับภาชนะที่มีผลต่าง (BR-06)');
        }

        foreach ($diffLines as $line) {
            $container = $line->container()->firstOrFail();
            $item = $container->item()->firstOrFail();
            $remarkPrefix = $line->reason !== null ? $line->reason.' — ' : '';

            /** @var numeric-string $diffBase */
            $diffBase = $line->diff_base;
            /** @var int $countedBy */
            $countedBy = $line->counted_by;

            $this->ledgerService->adjust($container->id, $diffBase, new LedgerEntryData(
                displayUnitId: $item->base_unit_id,
                createdBy: $countedBy,
                refType: 'STOCKTAKE',
                refId: $stockTake->id,
                refDocNo: $stockTake->doc_no,
                remark: $remarkPrefix."ผลต่างจากการตรวจนับ {$stockTake->doc_no}",
                approvedBy: $approver->id,
            ));
        }

        $stockTake->status = 'APPROVED';
        $stockTake->approved_by = $approver->id;
        $stockTake->approved_at = now();
        $stockTake->save();

        return $stockTake;
    }

    public function cancel(StockTake $stockTake): StockTake
    {
        if (in_array($stockTake->status, ['APPROVED', 'CANCELLED'], true)) {
            throw new InvalidStockTakeException('ยกเลิกไม่ได้เพราะรอบนี้เสร็จสิ้นหรือถูกยกเลิกไปแล้ว');
        }

        $stockTake->status = 'CANCELLED';
        $stockTake->save();

        return $stockTake;
    }
}
