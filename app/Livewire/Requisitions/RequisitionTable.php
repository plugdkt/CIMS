<?php

declare(strict_types=1);

namespace App\Livewire\Requisitions;

use App\Models\Requisition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** FR-RQ-01: requisition.view_all sees every requisition; requisition.view_own is scoped to one's own (as requester or advisor). */
#[Layout('components.layout')]
final class RequisitionTable extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', Requisition::class);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $query = Requisition::with(['lab', 'requester'])->orderByDesc('id');

        if (! $user->can('requisition.view_all')) {
            $query->where(function ($q) use ($user) {
                $q->where('requester_id', $user->id)->orWhere('advisor_id', $user->id);
            });
        } elseif ($user->isBranchManager()) {
            $query->where('lab_id', $user->lab_id);
        }

        return view('livewire.requisitions.requisition-table', [
            'requisitions' => $query->paginate(20),
        ]);
    }
}
