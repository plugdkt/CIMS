<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One "add to cart" click: which item, how much, in which unit. Availability is checked in the controller (needs the branch). */
final class ShopCartAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Requisition::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item' => ['required', 'string', Rule::exists('items', 'ulid')],
            'qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'qty.required' => __('shop.error.qty_required'),
            'qty.gt' => __('shop.error.qty_required'),
        ];
    }
}
