<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InvalidGoodsReceiptStateException;
use App\Domain\Inventory\Services\GoodsReceiptService;
use App\Http\Requests\GoodsReceiptItemRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;

final class GoodsReceiptItemController extends Controller
{
    public function store(GoodsReceiptItemRequest $request, GoodsReceipt $goodsReceipt, GoodsReceiptService $service): RedirectResponse
    {
        $data = $request->validated();
        $item = Item::whereKey($data['item_id'])->firstOrFail();
        $unit = Unit::whereKey($data['unit_id'])->firstOrFail();

        /** @var numeric-string $qtyPerContainer */
        $qtyPerContainer = (string) $data['qty_per_container'];

        try {
            $service->addLine(
                $goodsReceipt,
                $item,
                $unit,
                (int) $data['container_count'],
                $qtyPerContainer,
                [
                    'lot_no' => $data['lot_no'] ?? null,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'location_id' => $data['location_id'] ?? null,
                    'unit_price' => $data['unit_price'] ?? null,
                ],
            );
        } catch (InvalidGoodsReceiptStateException $e) {
            return back()->withErrors(['line' => $e->getMessage()]);
        }

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', __('goods_receipts.line_added'));
    }

    public function destroy(GoodsReceipt $goodsReceipt, GoodsReceiptItem $goodsReceiptItem): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        if ($goodsReceipt->status !== 'DRAFT') {
            return back()->withErrors(['line' => __('goods_receipts.validation.not_draft')]);
        }

        $goodsReceiptItem->delete();

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', __('goods_receipts.line_removed'));
    }
}
