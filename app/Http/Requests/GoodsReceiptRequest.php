<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\GoodsReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-RC-01: header fields — the GRN's own doc_no/status/received_by are set by the service, not user input. */
final class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $goodsReceipt = $this->route('goods_receipt');

        if ($user === null) {
            return false;
        }

        return $goodsReceipt instanceof GoodsReceipt
            ? $user->can('update', $goodsReceipt)
            : $user->can('create', GoodsReceipt::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'receipt_date' => ['required', 'date'],
            'po_no' => ['nullable', 'string', 'max:64'],
            'invoice_no' => ['nullable', 'string', 'max:64'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'lab_id' => ['required', 'integer', Rule::exists('labs', 'id')],
            'remark' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'receipt_date.required' => __('goods_receipts.validation.receipt_date_required'),
            'lab_id.required' => __('goods_receipts.validation.lab_required'),
        ];
    }
}
