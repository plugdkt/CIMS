<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Domain\Loan\Reports\LoanReportFilter;
use App\Domain\Loan\Reports\LoanReportScope;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ITM-2610-051 step 5: access and filters of the loan dashboard, the report pages and their CSV exports.
 *
 * Access: `loan.report` (LoanPolicy::browseReports). The branch is the user's own — a `lab_id` in the request is
 * only a CHOICE for ADMIN (who reads every branch). For anybody else, naming a branch that is not their own is
 * refused with 403 here (not quietly replaced), and a user with no branch can name none; without `lab_id` they get
 * their own branch, or an empty report when they have none (LoanReportScope).
 */
final class LoanReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->can('browseReports', Loan::class)) {
            return false;
        }

        $asked = $this->query('lab_id');
        if ($asked === null || $asked === '' || LoanReportScope::seesAllBranches($user)) {
            return true;
        }

        return is_string($asked) && ctype_digit($asked) && $user->lab_id !== null && (int) $asked === (int) $user->lab_id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lab_id' => ['nullable', 'integer', Rule::exists('labs', 'id')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'month' => ['nullable', 'date_format:Y-m'],
            'borrower' => ['nullable', 'string', 'max:100'],
            'item' => ['nullable', 'string', 'max:100'],
            'min_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'kind' => ['nullable', Rule::in(['DAMAGED', 'LOST'])],
        ];
    }

    public function scope(): LoanReportScope
    {
        /** @var User $user */
        $user = $this->user();
        $labId = $this->validated('lab_id');

        return LoanReportScope::forUser($user, $labId === null || $labId === '' ? null : (int) $labId);
    }

    public function filter(): LoanReportFilter
    {
        return new LoanReportFilter(
            from: $this->text('from'),
            to: $this->text('to'),
            month: $this->text('month'),
            borrower: $this->text('borrower'),
            item: $this->text('item'),
            minDays: $this->validated('min_days') === null ? null : (int) $this->validated('min_days'),
            kind: $this->text('kind'),
        );
    }

    private function text(string $key): ?string
    {
        $value = $this->validated($key);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
