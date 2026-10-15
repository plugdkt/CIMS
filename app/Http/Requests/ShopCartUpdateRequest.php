<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;

final class ShopCartUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Requisition::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['qty.gt' => __('shop.error.qty_required'), 'qty.required' => __('shop.error.qty_required')];
    }
}
