<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/{loan}/manager-decide: a scientist / warehouse manager of the loan's branch approves or rejects.
 * `qty_approved` maps the line number to a (possibly lower) whole quantity; blank = as requested.
 */
final class LoanManagerDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('managerDecideLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:decision,REJECT'],
            'qty_approved' => ['nullable', 'array', 'max:50'],
            'qty_approved.*' => ['nullable', 'string', 'regex:/^[0-9]{1,9}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'decision.required' => __('loans.validation.decision_required'),
            'decision.in' => __('loans.validation.decision_required'),
            'reason.required_if' => __('loans.error.reason_required'),
            'qty_approved.*.regex' => __('loans.error.qty_approved_invalid'),
        ];
    }
}
