<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GoodsReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-RC-01/03: one line = one item + how many containers of what size, at what unit. */
final class GoodsReceiptItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $goodsReceipt = $this->route('goods_receipt');

        return $user !== null && $goodsReceipt instanceof GoodsReceipt && $user->can('update', $goodsReceipt);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'container_count' => ['required', 'integer', 'min:1', 'max:65535'],
            'qty_per_container' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'lot_no' => ['nullable', 'string', 'max:64'],
            'expiry_date' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'item_id.required' => __('goods_receipts.validation.item_required'),
            'container_count.required' => __('goods_receipts.validation.container_count_required'),
            'container_count.min' => __('goods_receipts.validation.container_count_min'),
            'qty_per_container.required' => __('goods_receipts.validation.qty_per_container_required'),
            'qty_per_container.gt' => __('goods_receipts.validation.qty_per_container_gt'),
            'unit_id.required' => __('goods_receipts.validation.unit_required'),
        ];
    }
}
