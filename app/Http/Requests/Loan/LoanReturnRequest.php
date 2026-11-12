<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Domain\Loan\Services\LoanLedgerService;
use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/{loan}/return (step 3): the counter takes pieces back. `lines` maps the line number to the whole
 * quantity that came back usable now (blank / 0 = nothing on that line), its condition and a note.
 * Step 4: the same row may also carry `qty_damaged` (came back damaged) and `qty_lost` (will not come back), with
 * `incident_note` saying what happened (10+ characters; checked by the service); each becomes an incident.
 * `outcome` (OK / DAMAGED / LOST) is the older single-outcome shape, still accepted: it says what `qty` is.
 */
final class LoanReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('receiveReturnLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'max:50'],
            'lines.*' => ['array'],
            'lines.*.qty' => ['nullable', 'string', 'regex:/^[0-9]{1,9}$/'],
            'lines.*.condition' => ['nullable', Rule::in(LoanLedgerService::CONDITIONS)],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
            'lines.*.outcome' => ['nullable', Rule::in(['OK', 'DAMAGED', 'LOST'])],
            'lines.*.qty_damaged' => ['nullable', 'string', 'regex:/^[0-9]{1,9}$/'],
            'lines.*.qty_lost' => ['nullable', 'string', 'regex:/^[0-9]{1,9}$/'],
            'lines.*.incident_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.required' => __('loans.error.return_nothing'),
            'lines.*.qty.regex' => __('loans.error.qty_whole'),
            'lines.*.qty_damaged.regex' => __('loans.error.qty_whole'),
            'lines.*.qty_lost.regex' => __('loans.error.qty_whole'),
            'lines.*.condition.in' => __('loans.error.condition_required'),
            'lines.*.outcome.in' => __('loans.error.return_type_invalid'),
        ];
    }
}
