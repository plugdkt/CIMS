<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-RQ-02/03: the requester's own identity fields (name, phone, status, student code,
 * program, faculty) are never taken from this request — they're snapshotted server-side
 * from the authenticated user's profile (BR-11 is the only place those get edited).
 */
final class RequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requisition = $this->route('requisition');

        if ($user === null) {
            return false;
        }

        return $requisition instanceof Requisition
            ? $user->can('update', $requisition)
            : $user->can('create', Requisition::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lab_id' => ['required', 'integer', Rule::exists('labs', 'id')],
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
            'lab_id.required' => __('requisitions.validation.lab_required'),
            'request_type.required' => __('requisitions.validation.request_type_required'),
            'request_type.min' => __('requisitions.validation.request_type_required'),
            'purpose_type.required' => __('requisitions.validation.purpose_type_required'),
        ];
    }
}
