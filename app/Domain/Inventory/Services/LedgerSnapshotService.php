<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\LedgerSnapshot;
use App\Models\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * T-047: one `ledger_snapshots` row per (item, calendar month) — spec's own stated
 * purpose is performance, not a new user-facing feature (`ledger_snapshots` never
 * appears in any FR-8 report or UI screen). `closing_base` is always read directly off
 * the last `stock_ledger` row in the period (BR-07's own running balance, the single
 * source of truth) rather than recomputed as opening+in-out, so this can never drift
 * from the ledger itself; `total_in_base`/`total_out_base` are informational sums.
 */
final class LedgerSnapshotService
{
    private const SCALE = 6;

    /** @return Collection<int, LedgerSnapshot> */
    public function generateForPeriod(Carbon $month): Collection
    {
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();
        $periodYm = $this->periodYm($periodStart);

        return $this->itemIdsNeedingSnapshot($periodEnd)
            ->map(fn (int $itemId) => $this->generateForItem($itemId, $periodStart, $periodEnd, $periodYm))
            ->values();
    }

    /**
     * Every item with ledger activity up to this period, or with an earlier snapshot
     * already on record — once an item starts being tracked, every later month gets a
     * snapshot (even a zero-movement one) so the monthly chain never has a gap.
     *
     * @return Collection<int, int>
     */
    private function itemIdsNeedingSnapshot(Carbon $periodEnd): Collection
    {
        $withActivity = StockLedger::where('txn_date', '<=', $periodEnd->toDateString())->distinct()->pluck('item_id');
        $withPriorSnapshot = LedgerSnapshot::distinct()->pluck('item_id');

        return $withActivity->merge($withPriorSnapshot)->unique()->values();
    }

    private function generateForItem(int $itemId, Carbon $periodStart, Carbon $periodEnd, string $periodYm): LedgerSnapshot
    {
        $previous = LedgerSnapshot::where('item_id', $itemId)
            ->where('period_ym', '<', $periodYm)
            ->orderByDesc('period_ym')
            ->first();

        if ($previous !== null) {
            $openingBase = $previous->closing_base;
        } else {
            /** @var numeric-string $openingBase */
            $openingBase = StockLedger::where('item_id', $itemId)
                ->where('txn_date', '<', $periodStart->toDateString())
                ->orderByDesc('id')
                ->value('balance_base') ?? '0.000000';
        }

        $rowsInPeriod = StockLedger::where('item_id', $itemId)
            ->whereBetween('txn_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('id')
            ->get();

        $totalIn = '0.000000';
        $totalOut = '0.000000';
        foreach ($rowsInPeriod as $row) {
            $totalIn = bcadd($totalIn, $row->qty_in_base, self::SCALE);
            $totalOut = bcadd($totalOut, $row->qty_out_base, self::SCALE);
        }

        $lastRow = $rowsInPeriod->last();
        if ($lastRow === null) {
            $closingBase = $openingBase;
            $lastLedgerId = $previous === null ? 0 : $previous->last_ledger_id;
        } else {
            $closingBase = $lastRow->balance_base;
            $lastLedgerId = $lastRow->id;
        }

        return LedgerSnapshot::updateOrCreate(
            ['item_id' => $itemId, 'period_ym' => $periodYm],
            [
                'opening_base' => $openingBase,
                'total_in_base' => $totalIn,
                'total_out_base' => $totalOut,
                'closing_base' => $closingBase,
                'last_ledger_id' => $lastLedgerId,
                'generated_at' => now(),
            ],
        );
    }

    /** Buddhist-era "YYYY-MM" (BR-09's own convention, e.g. "2569-08"), matching DocumentNumberGenerator. */
    private function periodYm(Carbon $date): string
    {
        return ($date->year + 543).'-'.$date->format('m');
    }
}
