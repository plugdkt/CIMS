<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\ItemType;
use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The same header fields as a normal requisition; the lines come from the basket of one kind.
 * ITM-2610-052 phase 2: `type` says which basket is being checked out. It is NOT the old ticked
 * `request_type` box (removed): the requisition's kind is set by the system from the basket.
 */
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
            'type' => ['required', Rule::in(ItemType::requisitionKinds())],
            'purpose_type' => ['required', Rule::in(['TEACHING', 'RESEARCH', 'OTHER'])],
            'purpose_detail' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.required' => __('requisitions.validation.type_required'),
            'type.in' => __('requisitions.validation.type_required'),
            'purpose_type.required' => __('requisitions.validation.purpose_type_required'),
        ];
    }
}
