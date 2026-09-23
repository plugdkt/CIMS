<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-RQ-08: "เห็นควรให้เบิก" / "ไม่เห็นควรให้เบิก" — reject requires a reason. */
final class RequisitionScientistDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requisition = $this->route('requisition');

        return $user !== null && $requisition instanceof Requisition && $user->can('scientistDecide', $requisition);
    }

    /**
     * `qty_approved.*` is validated only for shape here (a plain non-negative number) —
     * whether it exceeds what was requested, and whether reducing it needed a reason, are
     * BR-04-style business rules checked in `ApprovalService::scientistDecide()` itself,
     * the same way REJECT's reason requirement is split between here (present) and the
     * service (non-empty after trimming).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:decision,REJECT'],
            'qty_approved' => ['nullable', 'array'],
            'qty_approved.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => __('requisitions.validation.decision_required'),
            'reason.required_if' => __('requisitions.validation.reject_reason_required'),
        ];
    }
}
