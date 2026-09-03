<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidAdjustmentException;
use App\Models\Container;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of `stock_ledger` (spec §4.2) — no other class may create a
 * StockLedger row. Every method locks the container and the item's last ledger
 * row for update (BR-07: running balance is only ever derived from that locked
 * row, never re-summed), and retries 3 times on a MySQL/MariaDB deadlock.
 *
 * `return()` and `adjust()` only implement the ledger-writing primitive BR-05/
 * BR-06 describe — the surrounding workflow (which issue a return is against,
 * the stocktake approval step) is wired later where those flows actually exist
 * (T-040 Return flow, T-041/043 stocktake + adjustment), same as
 * LocationIncompatibilityChecker in T-016. See CLAUDE.md.
 */
final class LedgerService
{
    private const SCALE = 6;

    public function __construct(private readonly LedgerHasher $hasher)
    {
    }

    /**
     * GRN confirm (T-022) calls this once per container to record its initial stock.
     *
     * @param  numeric-string  $qtyBase
     */
    public function receive(int $containerId, string $qtyBase, LedgerEntryData $ctx): StockLedger
    {
        return DB::transaction(function () use ($containerId, $qtyBase, $ctx) {
            $container = Container::whereKey($containerId)->lockForUpdate()->firstOrFail();
            $last = $this->lockLastRow($container->item_id);
            $balance = bcadd($this->balanceOf($last), $qtyBase, self::SCALE);

            $row = $this->appendRow($container, 'RECEIVE', $qtyBase, '0', $balance, $last?->row_hash, $ctx);

            $container->remaining_qty_base = bcadd($container->remaining_qty_base, $qtyBase, self::SCALE);
            $container->save();

            return $row;
        }, 3);
    }

    /**
     * จ่ายออกจาก container พร้อมเขียน ledger — เป็นทางเดียวที่อนุญาตให้ลดสต็อก
     *
     * @param  numeric-string  $qtyBase
     */
    public function issue(int $containerId, string $qtyBase, LedgerEntryData $ctx): StockLedger
    {
        return DB::transaction(function () use ($containerId, $qtyBase, $ctx) {
            $container = Container::whereKey($containerId)->lockForUpdate()->firstOrFail();

            if (bccomp($container->remaining_qty_base, $qtyBase, self::SCALE) < 0) {
                throw new InsufficientStockException(
                    "ปริมาณคงเหลือไม่เพียงพอ (คงเหลือ {$container->remaining_qty_base})"
                );
            }

            $last = $this->lockLastRow($container->item_id);
            $balance = bcsub($this->balanceOf($last), $qtyBase, self::SCALE);

            $row = $this->appendRow($container, 'ISSUE', '0', $qtyBase, $balance, $last?->row_hash, $ctx);

            $container->remaining_qty_base = bcsub($container->remaining_qty_base, $qtyBase, self::SCALE);
            $container->status = bccomp($container->remaining_qty_base, '0', self::SCALE) === 0
                ? 'EMPTY'
                : 'IN_USE';
            if ($container->opened_at === null) {
                $container->opened_at = now()->toDateString();
            }
            $container->save();

            return $row;
        }, 3);
    }

    /**
     * BR-05: คืนของกลับเข้า container เดิมเท่านั้น (การผูกกับรายการจ่ายเดิมอยู่ที่ T-040)
     *
     * @param  numeric-string  $qtyBase
     */
    public function return(int $containerId, string $qtyBase, LedgerEntryData $ctx): StockLedger
    {
        return DB::transaction(function () use ($containerId, $qtyBase, $ctx) {
            $container = Container::whereKey($containerId)->lockForUpdate()->firstOrFail();
            $last = $this->lockLastRow($container->item_id);
            $balance = bcadd($this->balanceOf($last), $qtyBase, self::SCALE);

            $row = $this->appendRow($container, 'RETURN', $qtyBase, '0', $balance, $last?->row_hash, $ctx);

            $container->remaining_qty_base = bcadd($container->remaining_qty_base, $qtyBase, self::SCALE);
            if ($container->status === 'EMPTY') {
                $container->status = 'IN_USE';
            }
            $container->save();

            return $row;
        }, 3);
    }

    /**
     * BR-06: ห้ามแก้ไขแถวเดิมทุกกรณี — ออกแถวใหม่ ADJUST_IN/ADJUST_OUT เท่านั้น.
     * $signedQtyBase บวก = ADJUST_IN (ยอดเพิ่ม), ลบ = ADJUST_OUT (ยอดลด).
     *
     * @param  numeric-string  $signedQtyBase
     */
    public function adjust(int $containerId, string $signedQtyBase, LedgerEntryData $ctx): StockLedger
    {
        if (mb_strlen((string) $ctx->remark) < 10) {
            throw new InvalidAdjustmentException('การปรับปรุงยอดต้องระบุหมายเหตุอย่างน้อย 10 ตัวอักษร (BR-06)');
        }

        if ($ctx->approvedBy === null || $ctx->approvedBy === $ctx->createdBy) {
            throw new InvalidAdjustmentException(
                'ผู้อนุมัติการปรับปรุงยอดต้องไม่ใช่คนเดียวกับผู้สร้างรายการ (BR-06)'
            );
        }

        if (bccomp($signedQtyBase, '0', self::SCALE) === 0) {
            throw new InvalidAdjustmentException('จำนวนที่ปรับปรุงต้องไม่เป็นศูนย์');
        }

        return DB::transaction(function () use ($containerId, $signedQtyBase, $ctx) {
            $container = Container::whereKey($containerId)->lockForUpdate()->firstOrFail();

            $isIncrease = bccomp($signedQtyBase, '0', self::SCALE) > 0;
            $qtyAbs = $isIncrease ? $signedQtyBase : bcmul($signedQtyBase, '-1', self::SCALE);

            $newRemaining = bcadd($container->remaining_qty_base, $signedQtyBase, self::SCALE);
            if (bccomp($newRemaining, '0', self::SCALE) < 0) {
                throw new InsufficientStockException(
                    "ปรับปรุงยอดไม่ได้ เพราะจะทำให้คงเหลือติดลบ (คงเหลือ {$container->remaining_qty_base})"
                );
            }

            $last = $this->lockLastRow($container->item_id);
            $balance = $isIncrease
                ? bcadd($this->balanceOf($last), $qtyAbs, self::SCALE)
                : bcsub($this->balanceOf($last), $qtyAbs, self::SCALE);

            $txnType = $isIncrease ? 'ADJUST_IN' : 'ADJUST_OUT';
            $qtyIn = $isIncrease ? $qtyAbs : '0';
            $qtyOut = $isIncrease ? '0' : $qtyAbs;

            $row = $this->appendRow($container, $txnType, $qtyIn, $qtyOut, $balance, $last?->row_hash, $ctx);

            $container->remaining_qty_base = $newRemaining;
            $container->save();

            return $row;
        }, 3);
    }

    private function lockLastRow(int $itemId): ?StockLedger
    {
        return StockLedger::where('item_id', $itemId)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /** @return numeric-string */
    private function balanceOf(?StockLedger $last): string
    {
        return $last === null ? '0' : $last->balance_base;
    }

    /**
     * @param  numeric-string  $qtyInBase
     * @param  numeric-string  $qtyOutBase
     * @param  numeric-string  $balanceBase
     */
    private function appendRow(
        Container $container,
        string $txnType,
        string $qtyInBase,
        string $qtyOutBase,
        string $balanceBase,
        ?string $prevRowHash,
        LedgerEntryData $ctx,
    ): StockLedger {
        $row = new StockLedger([
            'item_id' => $container->item_id,
            'container_id' => $container->id,
            'txn_date' => now()->toDateString(),
            'txn_type' => $txnType,
            'ref_type' => $ctx->refType,
            'ref_id' => $ctx->refId,
            'ref_doc_no' => $ctx->refDocNo,
            'qty_in_base' => $qtyInBase,
            'qty_out_base' => $qtyOutBase,
            'balance_base' => $balanceBase,
            'display_unit_id' => $ctx->displayUnitId,
            'issuer_id' => $ctx->issuerId,
            'receiver_id' => $ctx->receiverId,
            'receiver_name' => $ctx->receiverName,
            'signature_hash' => $ctx->signatureHash,
            'remark' => $ctx->remark,
            'created_by' => $ctx->createdBy,
            'created_at' => now(),
            'prev_row_hash' => $prevRowHash,
        ]);
        $row->row_hash = $this->hasher->compute($row);
        $row->save();

        return $row;
    }
}
