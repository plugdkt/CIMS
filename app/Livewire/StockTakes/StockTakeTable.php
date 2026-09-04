<?php

declare(strict_types=1);

namespace App\Livewire\StockTakes;

use App\Models\StockTake;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layout')]
final class StockTakeTable extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', StockTake::class);
    }

    public function render(): View
    {
        $stockTakes = StockTake::with(['lab'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.stock-takes.stock-take-table', [
            'stockTakes' => $stockTakes,
        ]);
    }
}
