<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Inventory\ItemDetailFields;
use App\Models\ImsLot;
use App\Models\ItemCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edit the material's details from the IMS lot page. Same fields and rules as the items register
 * ({@see ItemDetailFields}); only the keys in that list are ever accepted (item_code, units, package size, controlled
 * flags... stay register-only). Authorised by ImsLotPolicy::editItem on the lot in the URL; a move to another kind
 * also needs that new kind to be one the user looks after.
 */
final class ImsLotItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $lot = $this->route('ims_lot');

        return $user !== null && $lot instanceof ImsLot && $user->can('editItem', $lot);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ItemDetailFields::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ItemDetailFields::messages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $user = $this->user();
            $categoryId = $this->input('category_id');
            if ($user === null || $v->errors()->has('category_id') || ! is_numeric($categoryId)) {
                return;
            }

            $category = ItemCategory::query()->find((int) $categoryId);
            if ($category !== null && ! $user->managesItemType($category->kind())) {
                $v->errors()->add('category_id', (string) __('ims.item_edit.kind_not_managed'));
            }
        });
    }
}
