<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The same header fields as a normal requisition; the lines come from the cart. */
final class ShopCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Requisition::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_type' => ['required', 'array', 'min:1'],
            'request_type.*' => [Rule::in(['CHEMICAL', 'CONSUMABLE'])],
            'purpose_type' => ['required', Rule::in(['TEACHING', 'RESEARCH', 'OTHER'])],
            'purpose_detail' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'request_type.required' => __('requisitions.validation.request_type_required'),
            'request_type.min' => __('requisitions.validation.request_type_required'),
            'purpose_type.required' => __('requisitions.validation.purpose_type_required'),
        ];
    }
}
