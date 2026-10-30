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

    /**
     * ITM-2610-042: a non-ADMIN user may only file/move a GRN under their own branch. Explicit
     * Thai error instead of a silent reject (covers both store and update).
     *
     * @return array<int, \Closure(\Illuminate\Validation\Validator): void>
     */
    public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            $user = $this->user();
            $labId = $this->input('lab_id');

            if ($user === null || $user->hasRole('ADMIN') || $validator->errors()->has('lab_id')) {
                return;
            }

            if ($user->lab_id === null || (int) $labId !== (int) $user->lab_id) {
                $validator->errors()->add('lab_id', __('goods_receipts.validation.lab_not_own_branch'));
            }
        }];
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
