<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StockLedger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-LG-07 / BR-06: one form names both the adjustment and its distinct, authorized approver. */
final class AdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('adjust', StockLedger::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', Rule::exists('containers', 'barcode')],
            'direction' => ['required', Rule::in(['IN', 'OUT'])],
            'qty' => ['required', 'numeric', 'gt:0'],
            'remark' => ['required', 'string', 'min:10', 'max:500'],
            'approved_by' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required' => __('adjustments.validation.barcode_required'),
            'barcode.exists' => __('adjustments.validation.barcode_not_found'),
            'qty.required' => __('adjustments.validation.qty_required'),
            'qty.gt' => __('adjustments.validation.qty_gt'),
            'remark.required' => __('adjustments.validation.remark_required'),
            'remark.min' => __('adjustments.validation.remark_min'),
            'approved_by.required' => __('adjustments.validation.approver_required'),
        ];
    }
}
