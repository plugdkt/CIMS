<?php

declare(strict_types=1);

namespace App\Livewire\GoodsReceipts;

use App\Models\GoodsReceipt;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layout')]
final class GoodsReceiptTable extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', GoodsReceipt::class);
    }

    public function render(): View
    {
        $goodsReceipts = GoodsReceipt::with(['lab'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.goods-receipts.goods-receipt-table', [
            'goodsReceipts' => $goodsReceipts,
        ]);
    }
}
