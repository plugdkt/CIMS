<?php

declare(strict_types=1);

namespace App\Livewire\Requisitions;

use App\Models\Requisition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "จัดการใบเบิก" — the warehouse side: every requisition of the branch that a manager must
 * decide or dispense (`requisition.view_all`, branch-scoped for branch managers; ADMIN sees all),
 * and for a dispenser (`requisition.issue`) the approved ones waiting on them. Requesters use
 * {@see RequisitionTable} ("ใบเบิกของฉัน") instead.
 */
#[Layout('components.layout')]
final class RequisitionManageTable extends Component
{
    use WithPagination;

    /** @var array<string, list<string>|null> status groups behind the tabs */
    public const TABS = [
        'all' => null,
        'waiting' => ['SUBMITTED', 'ADVISOR_APPROVED'],
        'to_issue' => ['APPROVED', 'PARTIALLY_ISSUED'],
        'done' => ['ISSUED'],
        'closed' => ['REJECTED', 'CANCELLED'],
    ];

    #[Url(as: 'tab')]
    public string $tab = 'all';

    public function mount(): void
    {
        $this->authorize('manage', Requisition::class);
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'all';
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        // A draft is still the requester's own work-in-progress; it only reaches the warehouse once submitted.
        $base = Requisition::query()->where('status', '!=', 'DRAFT');

        if (! $user->can('requisition.view_all')) {
            if ($user->lab_id === null) {
                // No branch = nothing (mirrors RequisitionPolicy::sharesBranch()).
                $base->whereIn('id', []);
            } elseif ($user->can('requisition.approve_scientist')) {
                // approvals.md K (2026-11-04): a scientist approves their branch's requisitions as well as
                // dispensing them, so they see every submitted one of the branch (drafts excluded above).
                $base->where('lab_id', $user->lab_id);
            } else {
                // A dispenser (requisition.issue) sees only their own branch's requisitions that
                // are ready to dispense or already dispensed (returns are still possible) —
                // never ones still awaiting a decision. Mirrors RequisitionPolicy::sharesBranch().
                $base->where('lab_id', $user->lab_id)->whereIn('status', ['APPROVED', 'PARTIALLY_ISSUED', 'ISSUED']);
            }
        } elseif ($user->isBranchManager()) {
            // Phase 3: own branch, and only the kinds of stock this manager is responsible for
            // (none assigned = nothing, deny by default; a legacy mixed one shows if either kind is held).
            $base->where('lab_id', $user->lab_id)->ofAnyItemType($user->managedItemTypes());
        }

        // Counts per tab for the badges (same scope, before the tab filter).
        $counts = [];
        foreach (self::TABS as $key => $statuses) {
            $counts[$key] = (clone $base)->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses))->count();
        }

        $statuses = self::TABS[$this->tab] ?? null;
        $requisitions = $base->with(['lab', 'requester'])->withCount('items')
            ->when($statuses !== null, fn ($q) => $q->whereIn('status', $statuses))
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.requisitions.requisition-manage-table', [
            'requisitions' => $requisitions,
            'counts' => $counts,
            'tabs' => array_keys(self::TABS),
        ]);
    }
}
