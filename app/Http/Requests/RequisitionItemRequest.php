<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Container;
use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FR-RQ-04: one requisition line = one item + how much, in what unit.
 *
 * `item_id` also has to actually have stock in the requisition's own lab — the
 * add-line picker (a type-to-search box, not a full-catalog <select>) already only
 * offers matches scoped that way, but `withValidator()` below is the real gate:
 * without it, a direct POST could still name an item this branch never stocked.
 */
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $itemId = $this->input('item_id');
            if (! is_numeric($itemId)) {
                return;
            }

            /** @var Requisition $requisition */
            $requisition = $this->route('requisition');

            $hasStockInOwnLab = Container::query()
                ->where('item_id', (int) $itemId)
                ->whereIn('status', ['SEALED', 'IN_USE'])
                ->where('remaining_qty_base', '>', 0)
                ->whereHas('location', fn ($q) => $q->where('lab_id', $requisition->lab_id))
                ->exists();

            if (! $hasStockInOwnLab) {
                $validator->errors()->add('item_id', __('requisitions.validation.item_not_in_lab_stock'));
            }
        });
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
