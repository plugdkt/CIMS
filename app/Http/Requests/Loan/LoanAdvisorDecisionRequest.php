<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /loans/{loan}/advisor-decide: the logged-in advisor's decision (the signed-link channel uses LoanSignedDecisionRequest). */
final class LoanAdvisorDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('advisorDecideLoan', $loan);
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
