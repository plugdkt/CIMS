<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Inventory\Services\StockBalanceService;
use App\Models\Container;
use App\Models\IssueTransaction;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * §7.9 Dashboard. The pending-requisition count is the one card spec explicitly says
 * varies "แยกตาม role ของผู้ใช้" — every other card (reorder/expiry/top-items/monthly
 * chart) is the same system-wide figure for every viewer who can see the dashboard at all.
 */
final class DashboardService
{
    public function __construct(private readonly StockBalanceService $stockBalance)
    {
    }

    /**
     * Sums every action-queue this user's permissions make them responsible for
     * (advisor decisions, scientist decisions, pending issuance); a plain requester with
     * none of those permissions sees their own requisitions still in flight instead.
     */
    public function pendingRequisitionsCount(User $user): int
    {
        $hasPermission = fn (string $code) => $user->roles->flatMap(fn ($role) => $role->permissions)->contains('code', $code);

        $count = 0;
        $matchedAnyActionQueue = false;

        if ($hasPermission('requisition.approve_advisor')) {
            $matchedAnyActionQueue = true;
            $count += Requisition::where('advisor_id', $user->id)->where('status', 'SUBMITTED')->count();
        }

        if ($hasPermission('requisition.approve_scientist')) {
            $matchedAnyActionQueue = true;
            $count += Requisition::where(function ($q) {
                $q->where('status', 'ADVISOR_APPROVED')
                    ->orWhere(fn ($q2) => $q2->where('status', 'SUBMITTED')->where('requester_status', '!=', 'STUDENT'));
            })->count();
        }

        if ($hasPermission('requisition.issue')) {
            $matchedAnyActionQueue = true;
            $count += Requisition::whereIn('status', ['APPROVED', 'PARTIALLY_ISSUED'])->count();
        }

        if ($matchedAnyActionQueue) {
            return $count;
        }

        return Requisition::where('requester_id', $user->id)
            ->whereNotIn('status', ['ISSUED', 'REJECTED', 'CANCELLED'])
            ->count();
    }

    /**
     * User-requested 2026-09-21: the dashboard should show *what* is running low, not
     * just a count — every item genuinely below its own reorder point, cheapest
     * (lowest remaining-vs-reorder-point ratio) first, so the most urgent one is on
     * top regardless of how many are flagged.
     *
     * @return Collection<int, array{item: Item, balance: numeric-string}>
     */
    public function belowReorderPointItems(): Collection
    {
        return Item::where('is_active', true)
            ->where('reorder_point_base', '>', 0)
            ->get()
            ->map(fn (Item $item) => ['item' => $item, 'balance' => $this->stockBalance->currentBalance($item)])
            ->filter(fn (array $row) => $this->stockBalance->isBelowReorderPoint($row['item'], $row['balance']))
            ->sortBy(fn (array $row) => (float) $row['balance'] / max((float) $row['item']->reorder_point_base, 0.000001))
            ->values();
    }

    public function belowReorderPointCount(): int
    {
        return $this->belowReorderPointItems()->count();
    }

    /** @return Collection<int, Container> */
    public function expiringWithin30DaysContainers(): Collection
    {
        return Container::whereIn('status', ['SEALED', 'IN_USE', 'QUARANTINE'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->with('item')
            ->orderBy('expiry_date')
            ->get();
    }

    public function expiringWithin30DaysCount(): int
    {
        return $this->expiringWithin30DaysContainers()->count();
    }

    /**
     * Ranked by issue frequency (transaction count), not summed quantity — items are
     * measured in incompatible units (mg vs mL vs pcs), so a quantity total across
     * different items wouldn't be a meaningful ranking. Grouped/counted in PHP (AGENT
     * RULE #3 — no raw SQL), which is fine at this app's scale (issue_transactions is
     * a low-write-volume table).
     *
     * @return Collection<int, array{item: Item, issue_count: int}>
     */
    public function topIssuedItems(int $limit = 10): Collection
    {
        $itemIdsByTransaction = IssueTransaction::where('issued_at', '>=', now()->subMonths(3))
            ->with('requisitionItem:id,item_id')
            ->get()
            ->map(fn (IssueTransaction $row) => $row->requisitionItem?->item_id)
            ->filter();

        $counts = $itemIdsByTransaction->countBy()->sortDesc()->take($limit);

        $items = Item::whereIn('id', $counts->keys())->get()->keyBy('id');

        return $counts
            ->map(fn (int $issueCount, int $itemId) => ['item' => $items->get($itemId), 'issue_count' => $issueCount])
            ->filter(fn (array $row) => $row['item'] !== null)
            ->values();
    }

    /**
     * Trailing 12 months, oldest first, each month's issue-transaction count — same
     * frequency-not-quantity reasoning as {@see topIssuedItems()}, grouped in PHP for
     * the same AGENT RULE #3 reason.
     *
     * @return Collection<int, array{month: Carbon, count: int}>
     */
    public function monthlyIssuanceSeries(): Collection
    {
        $since = now()->startOfMonth()->subMonths(11);

        $counts = IssueTransaction::where('issued_at', '>=', $since)
            ->get(['issued_at'])
            ->pluck('issued_at')
            ->countBy(fn (Carbon $issuedAt) => $issuedAt->format('Y-m'));

        return collect(range(0, 11))->map(fn (int $i) => [
            'month' => $month = $since->copy()->addMonths($i),
            'count' => (int) ($counts->get($month->format('Y-m')) ?? 0),
        ]);
    }
}
