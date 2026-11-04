<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loan-approve/{loan}: the advisor's emailed 72-hour signed link (public, no login). The `signed`
 * middleware is the credential; WHO may decide is decided by LoanApprovalService (named advisor, active, holds
 * `loan.approve_advisor`, not the borrower, status still waiting), so authorize() is open by design.
 */
final class LoanSignedDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'decision.required' => __('loans.validation.decision_required'),
            'decision.in' => __('loans.validation.decision_required'),
            'reason.required_if' => __('loans.error.reason_required'),
        ];
    }
}
