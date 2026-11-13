<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ITM-2610-051: create (POST /loans) and edit (PUT /loans/{loan}) a DRAFT request. Used by both routes.
 *
 * Deliberately NOT accepted from the form (BR-L14 / BR-L05): the branch, the borrower status, the advisor, the
 * status. The borrower is the logged-in user, or — for counter staff — a borrower picked by ULID, who is checked
 * again by BorrowerResolver (own branch, has a branch, active).
 *
 * `qty` maps a loan asset's ULID to the wanted whole quantity; the service ignores blank / 0 rows and checks the
 * assets belong to the borrower's branch.
 */
final class LoanStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $loan = $this->route('loan');
        if ($loan instanceof Loan) {
            return $user->can('updateLoan', $loan);
        }

        if ($this->filled('borrower') && ! $user->can('createOnBehalf', Loan::class)) {
            return false;
        }

        return $user->can('createLoan', Loan::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'purpose_type' => ['required', Rule::in(['TEACHING', 'RESEARCH', 'OTHER'])],
            'purpose_detail' => [Rule::requiredIf(fn () => $this->input('purpose_type') === 'OTHER'), 'nullable', 'string', 'max:500'],
            'loan_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:loan_date'],
            'remark' => ['nullable', 'string', 'max:500'],
            // BR-L08 (step 3): why an approver of the branch lets a borrower with long-overdue loans borrow this once.
            // Ignored unless the borrower is blocked AND the person typing is such an approver (LoanService decides).
            'overdue_override_reason' => ['nullable', 'string', 'max:500'],
            'borrower' => ['nullable', 'string', 'size:26', Rule::exists('users', 'ulid')],
            // ITM-2610-070: the form was opened from the borrowing cart; a saved request for oneself empties the cart.
            'from_cart' => ['nullable', Rule::in(['1'])],
            'qty' => ['required', 'array', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (array_keys((array) $value) as $key) {
                    if (preg_match('/^[0-9A-Za-z]{26}$/', (string) $key) !== 1) {
                        $fail(__('loans.validation.lines_invalid'));

                        return;
                    }
                }
            }],
            'qty.*' => ['nullable', 'string', 'regex:/^[0-9]{1,9}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'purpose_type.required' => __('loans.validation.purpose_required'),
            'purpose_type.in' => __('loans.validation.purpose_required'),
            'purpose_detail.required' => __('loans.validation.purpose_detail_required'),
            'loan_date.required' => __('loans.validation.date_invalid'),
            'loan_date.date_format' => __('loans.validation.date_invalid'),
            'due_date.required' => __('loans.validation.date_invalid'),
            'due_date.date_format' => __('loans.validation.date_invalid'),
            'due_date.after_or_equal' => __('loans.error.due_before_loan'),
            'qty.required' => __('loans.error.no_lines'),
            'qty.*.regex' => __('loans.error.qty_whole'),
            'borrower.exists' => __('loans.error.borrower_invalid'),
        ];
    }

    /** The borrower the counter staff picked (null = the logged-in user borrows). */
    public function onBehalfBorrowerId(): ?int
    {
        if (! $this->filled('borrower')) {
            return null;
        }

        /** @var User|null $borrower */
        $borrower = User::where('ulid', (string) $this->input('borrower'))->first();

        return $borrower?->id;
    }
}
