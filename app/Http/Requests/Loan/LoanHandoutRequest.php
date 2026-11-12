<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Domain\Loan\Services\LoanLedgerService;
use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/{loan}/handout (step 3): the counter hands out an APPROVED loan. `lines` maps the line number to the
 * condition written down for that line (BR-L09). Quantities are NOT accepted: what goes out is what was approved.
 * LoanCirculationService checks that every line of the loan got a condition.
 */
final class LoanHandoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('handOutLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'max:50'],
            'lines.*' => ['array'],
            'lines.*.condition' => ['required', Rule::in(LoanLedgerService::CONDITIONS)],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => __('loans.error.condition_required'),
            'lines.*.condition.required' => __('loans.error.condition_required'),
            'lines.*.condition.in' => __('loans.error.condition_required'),
        ];
    }
}
