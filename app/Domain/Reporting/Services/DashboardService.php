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
     * Sums action-queue requisitions for warehouse managers (AUDITOR/LAB_MANAGER/ADMIN)
     * scoped to branch; for requesters without requisition.view_all (SCIENTIST, STUDENT, STAFF),
     * scopes strictly to their own pending requisitions (and advisees for ADVISOR).
     */
    public function pendingRequisitionsCount(User $user): int
    {
        if ($user->can('requisition.view_all')) {
            $count = 0;

            if ($user->can('requisition.approve_scientist')) {
                $count += Requisition::where(function ($q) {
                    $q->where('status', 'ADVISOR_APPROVED')
                        ->orWhere(fn ($q2) => $q2->where('status', 'SUBMITTED')->where('requester_status', '!=', 'STUDENT'));
                })
                ->when($user->isBranchManager(), fn ($q) => $q->where('lab_id', $user->lab_id))
                ->count();
            }

            if ($user->can('requisition.issue')) {
                $count += Requisition::whereIn('status', ['APPROVED', 'PARTIALLY_ISSUED'])
                    ->when($user->isBranchManager(), fn ($q) => $q->where('lab_id', $user->lab_id))
                    ->count();
            }

            if ($count > 0) {
                return $count;
            }

            return Requisition::whereNotIn('status', ['ISSUED', 'REJECTED', 'CANCELLED'])
                ->when($user->isBranchManager(), fn ($q) => $q->where('lab_id', $user->lab_id))
                ->count();
        }

        $count = Requisition::where('requester_id', $user->id)
            ->whereNotIn('status', ['ISSUED', 'REJECTED', 'CANCELLED'])
            ->count();

        if ($user->can('requisition.approve_advisor')) {
            $count += Requisition::where('advisor_id', $user->id)
                ->where('status', 'SUBMITTED')
                ->count();
        }

        return $count;
    }

    /**
     * User-requested 2026-09-21: the dashboard should show *what* is running low, not
     * just a count — every item genuinely below its own reorder point, cheapest
     * (lowest remaining-vs-reorder-point ratio) first, so the most urgent one is on
     * top regardless of how many are flagged. Scoped to branch when labId is given.
     *
     * @return Collection<int, array{item: Item, balance: numeric-string}>
     */
    public function belowReorderPointItems(?int $labId = null): Collection
    {
        return Item::where('is_active', true)
            ->where('reorder_point_base', '>', 0)
            ->when($labId !== null, fn ($q) => $q->whereHas(
                'containers',
                fn ($c) => $c->whereHas('location', fn ($l) => $l->where('lab_id', $labId)),
            ))
            ->get()
            ->map(fn (Item $item) => ['item' => $item, 'balance' => $this->stockBalance->currentBalance($item)])
            ->filter(fn (array $row) => $this->stockBalance->isBelowReorderPoint($row['item'], $row['balance']))
            ->sortBy(fn (array $row) => (float) $row['balance'] / max((float) $row['item']->reorder_point_base, 0.000001))
            ->values();
    }

    public function belowReorderPointCount(?int $labId = null): int
    {
        return $this->belowReorderPointItems($labId)->count();
    }

    /** @return Collection<int, Container> */
    public function expiringWithin30DaysContainers(?int $labId = null): Collection
    {
        return Container::whereIn('status', ['SEALED', 'IN_USE', 'QUARANTINE'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->when($labId !== null, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('lab_id', $labId)))
            ->with('item')
            ->orderBy('expiry_date')
            ->get();
    }

    public function expiringWithin30DaysCount(?int $labId = null): int
    {
        return $this->expiringWithin30DaysContainers($labId)->count();
    }

    /**
     * Ranked by issue frequency (transaction count), not summed quantity — items are
     * measured in incompatible units (mg vs mL vs pcs), so a quantity total *across
     * different items* wouldn't be a meaningful ranking. Each row's own `qty_issued`
     * is a different thing (user-requested 2026-09-21) — it never sums *across* items,
     * only across one item's own transactions (already all in that item's own base
     * unit), so it carries none of the cross-item-unit risk the ranking metric avoids.
     * Grouped/summed in PHP with BCMath (AGENT RULE #2/#3 — no float math on
     * quantities, no raw SQL), which is fine at this app's scale (issue_transactions
     * is a low-write-volume table). Scoped to branch when labId is given.
     *
     * @return Collection<int, array{item: Item, issue_count: int, qty_issued: string}>
     */
    public function topIssuedItems(int $limit = 10, ?int $labId = null): Collection
    {
        /** @var array<int, array{count: int, qty: string}> $byItem */
        $byItem = [];

        IssueTransaction::where('issued_at', '>=', now()->subMonths(3))
            ->when($labId !== null, fn ($q) => $q->whereHas(
                'requisitionItem.requisition',
                fn ($r) => $r->where('lab_id', $labId),
            ))
            ->with('requisitionItem:id,item_id')
            ->get(['id', 'requisition_item_id', 'qty_issued_base'])
            ->each(function (IssueTransaction $row) use (&$byItem) {
                $itemId = $row->requisitionItem?->item_id;
                if ($itemId === null) {
                    return;
                }

                $existing = $byItem[$itemId] ?? ['count' => 0, 'qty' => '0.000000'];
                /** @var numeric-string $existingQty */
                $existingQty = $existing['qty'];
                $byItem[$itemId] = [
                    'count' => $existing['count'] + 1,
                    'qty' => bcadd($existingQty, $row->qty_issued_base, 6),
                ];
            });

        $top = collect($byItem)->sortByDesc('count')->take($limit);

        $items = Item::whereIn('id', $top->keys())->with('baseUnit')->get()->keyBy('id');

        return $top
            ->map(fn (array $data, int $itemId) => [
                'item' => $items->get($itemId),
                'issue_count' => $data['count'],
                'qty_issued' => $data['qty'],
            ])
            ->filter(fn (array $row) => $row['item'] !== null)
            ->values();
    }

    /**
     * Trailing 12 months, oldest first, each month's issue-transaction count — same
     * frequency-not-quantity reasoning as {@see topIssuedItems()}, grouped in PHP for
     * the same AGENT RULE #3 reason. Scoped to branch when labId is given.
     *
     * @return Collection<int, array{month: Carbon, count: int}>
     */
    public function monthlyIssuanceSeries(?int $labId = null): Collection
    {
        $since = now()->startOfMonth()->subMonths(11);

        $counts = IssueTransaction::where('issued_at', '>=', $since)
            ->when($labId !== null, fn ($q) => $q->whereHas(
                'requisitionItem.requisition',
                fn ($r) => $r->where('lab_id', $labId),
            ))
            ->get(['issued_at'])
            ->pluck('issued_at')
            ->countBy(fn (Carbon $issuedAt) => $issuedAt->format('Y-m'));

        return collect(range(0, 11))->map(fn (int $i) => [
            'month' => $month = $since->copy()->addMonths($i),
            'count' => (int) ($counts->get($month->format('Y-m')) ?? 0),
        ]);
    }
}
