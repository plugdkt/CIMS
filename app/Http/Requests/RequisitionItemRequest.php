<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-RQ-04: one requisition line = one item + how much, in what unit. */
final class RequisitionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requisition = $this->route('requisition');

        return $user !== null && $requisition instanceof Requisition && $user->can('update', $requisition);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'qty_requested' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'reference_doc' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'item_id.required' => __('requisitions.validation.item_required'),
            'qty_requested.required' => __('requisitions.validation.qty_requested_required'),
            'qty_requested.gt' => __('requisitions.validation.qty_requested_gt'),
            'unit_id.required' => __('requisitions.validation.unit_required'),
        ];
    }
}
