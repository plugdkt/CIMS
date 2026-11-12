<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/{loan}/renewals/{seq}/decide (step 3, BR-L07): an approver of the loan's branch approves or rejects a
 * waiting renewal. The request is addressed by the loan's ULID plus its sequence number, never by a row id.
 */
final class LoanRenewDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('decideRenewalLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'note' => ['nullable', 'string', 'max:500', 'required_if:decision,REJECT'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => __('loans.validation.decision_required'),
            'decision.in' => __('loans.validation.decision_required'),
            'note.required_if' => __('loans.error.reason_required'),
        ];
    }
}
