<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-RQ-07: shared by both approval channels — the public signed-URL route (its
 * `signed` middleware has already verified access, so an anonymous visitor is trusted
 * here) and the in-system route (gated by `RequisitionPolicy::advisorDecide`).
 */
final class RequisitionApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return true;
        }

        $requisition = $this->route('requisition');

        return $requisition instanceof Requisition && $user->can('advisorDecide', $requisition);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:decision,REJECT'],
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
