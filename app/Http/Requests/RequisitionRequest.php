<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\ItemType;
use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-RQ-02/03: the requester's own identity fields (name, phone, status, student code,
 * program, faculty) are never taken from this request — they're snapshotted server-side
 * from the authenticated user's profile (BR-11 is the only place those get edited).
 * `lab_id` joined this list once branch-scoped access control shipped: a requester's
 * branch is their own `users.lab_id`, never a value they submit (see
 * `RequisitionController::store()`).
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
        // ITM-2610-052 phase 2: the kind (one per requisition) is chosen only when creating; it is not an
        // editable field afterwards, and the old ticked `request_type` boxes are gone.
        $kind = $this->route('requisition') instanceof Requisition
            ? []
            : ['type' => ['required', Rule::in(ItemType::all())]];

        return $kind + [
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
