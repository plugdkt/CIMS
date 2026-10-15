<?php

declare(strict_types=1);

namespace App\Livewire\Requisitions;

use App\Models\Requisition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "ใบเบิกของฉัน" — the requester's side: only requisitions the user filed, plus those they
 * advise (so an advisor finds what is waiting on them). The warehouse side (everything in the
 * branch, to approve and dispense) is {@see RequisitionManageTable}.
 */
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

        $query->where(function ($q) use ($user) {
            $q->where('requester_id', $user->id)->orWhere('advisor_id', $user->id);
        });

        return view('livewire.requisitions.requisition-table', [
            'requisitions' => $query->paginate(20),
        ]);
    }
}
