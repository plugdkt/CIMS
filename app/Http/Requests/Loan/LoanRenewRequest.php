<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /loans/{loan}/renewals (step 3, BR-L07): the borrower asks for a later due date. The limits (how far, how
 * many times, not when overdue) are LoanRenewalService's.
 */
final class LoanRenewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('renewLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'new_due_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'new_due_date.required' => __('loans.validation.date_invalid'),
            'new_due_date.date_format' => __('loans.validation.date_invalid'),
        ];
    }
}
