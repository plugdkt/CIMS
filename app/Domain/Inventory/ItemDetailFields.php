<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Illuminate\Validation\Rule;

/**
 * The material-details fields that are edited from BOTH the items register (ItemRequest) and the IMS lot page
 * (ImsLotItemRequest): one list of keys and one set of rules, so the two screens can never drift apart.
 *
 * Deliberately NOT here (register only, shown read-only on the IMS page): item_code, the units / package size /
 * density (they define how every stored quantity is converted), reorder point, controlled-substance flags, GHS/H/P
 * statements, shelf life, expiry and is_active.
 */
final class ItemDetailFields
{
    /** @var list<string> */
    public const KEYS = [
        'category_id', 'name_th', 'name_en', 'cas_no', 'formula', 'brand', 'grade', 'physical_state', 'specification', 'storage_class',
    ];

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('item_categories', 'id')],
            'name_th' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'cas_no' => ['nullable', 'string', 'max:20'],
            'formula' => ['nullable', 'string', 'max:128'],
            'brand' => ['nullable', 'string', 'max:128'],
            'grade' => ['nullable', 'string', 'max:64'],
            'physical_state' => ['nullable', 'string', 'max:32', Rule::in(['liquid', 'solid', 'powder', 'solution', 'gas', 'crystal', 'pellet'])],
            'specification' => ['nullable', 'string'],
            'storage_class' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'category_id.required' => (string) __('items.validation.category_required'),
            'name_th.required' => (string) __('items.validation.name_th_required'),
        ];
    }
}
