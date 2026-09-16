<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-MD-01: fields per F-03 (brand/grade/package size/unit/sub-unit). */
final class ItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $item = $this->route('item');

        if ($user === null) {
            return false;
        }

        return $item instanceof Item
            ? $user->can('update', $item)
            : $user->can('create', Item::class);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('reorder_point_base')) {
            $this->merge(['reorder_point_base' => '0']);
        }

        if ($this->filled('base_unit_id') && ! $this->filled('package_unit_id')) {
            $this->merge(['package_unit_id' => $this->base_unit_id]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $item = $this->route('item');
        $itemId = $item instanceof Item ? $item->id : null;

        return [
            'item_code' => ['required', 'string', 'max:32', Rule::unique('items', 'item_code')->ignore($itemId)],
            'category_id' => ['required', 'integer', Rule::exists('item_categories', 'id')],
            'name_th' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'cas_no' => ['nullable', 'string', 'max:20'],
            'formula' => ['nullable', 'string', 'max:128'],
            'brand' => ['nullable', 'string', 'max:128'],
            'grade' => ['nullable', 'string', 'max:64'],
            'physical_state' => ['nullable', 'string', 'max:32', Rule::in(['liquid', 'solid', 'powder', 'solution', 'gas', 'crystal', 'pellet'])],
            'specification' => ['nullable', 'string'],
            'package_size' => ['nullable', 'numeric', 'min:0'],
            'package_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'sub_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'base_unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'density_g_per_ml' => ['nullable', 'numeric', 'min:0'],
            'reorder_point_base' => ['nullable', 'numeric', 'min:0'],
            'is_controlled' => ['boolean'],
            'control_class' => ['nullable', 'required_if:is_controlled,1', 'string', 'max:64'],
            'ghs_codes' => ['nullable', 'array'],
            'ghs_codes.*' => [Rule::in(array_keys(config('ghs.pictograms')))],
            'h_statements' => ['nullable', 'array'],
            'h_statements.*' => [Rule::in(array_keys(config('ghs.hazard_statements')))],
            'p_statements' => ['nullable', 'array'],
            'p_statements.*' => [Rule::in(array_keys(config('ghs.precautionary_statements')))],
            'storage_class' => ['nullable', 'string', 'max:64'],
            'shelf_life_days_after_open' => ['nullable', 'integer', 'min:0'],
            'expiry_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'item_code.required' => __('items.validation.item_code_required'),
            'item_code.unique' => __('items.validation.item_code_unique'),
            'category_id.required' => __('items.validation.category_required'),
            'name_th.required' => __('items.validation.name_th_required'),
            'base_unit_id.required' => __('items.validation.base_unit_required'),
            'control_class.required_if' => __('items.validation.control_class_required'),
            'ghs_codes.*.in' => __('items.validation.ghs_code_invalid'),
            'h_statements.*.in' => __('items.validation.h_statement_invalid'),
            'p_statements.*.in' => __('items.validation.p_statement_invalid'),
        ];
    }
}
