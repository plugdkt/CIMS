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
        $user = auth()->user();
        // ITM-2610-042: non-ADMIN sees only their own branch's GRNs (no branch => none).
        $goodsReceipts = GoodsReceipt::with(['lab'])
            ->when($user === null || ! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id ?? 0))
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.goods-receipts.goods-receipt-table', [
            'goodsReceipts' => $goodsReceipts,
            'noBranch' => $user !== null && ! $user->hasRole('ADMIN') && $user->lab_id === null,
        ]);
    }
}
