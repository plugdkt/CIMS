<?php

declare(strict_types=1);

namespace App\Livewire\Disposals;

use App\Models\Disposal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layout')]
final class DisposalTable extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', Disposal::class);
    }

    public function render(): View
    {
        $disposals = Disposal::with(['container.item'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.disposals.disposal-table', [
            'disposals' => $disposals,
        ]);
    }
}
