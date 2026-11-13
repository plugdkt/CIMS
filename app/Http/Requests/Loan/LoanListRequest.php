<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use App\Models\LoanIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and authorization of the loan list pages: "things I borrowed" (loans.mine), the request form's entry
 * (loans.create, which also carries the asset / borrower search), the approval queue (loans.queue) and the branch
 * list (loans.branch). Which ability applies depends on the route.
 */
final class LoanListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return match ($this->route()?->getName()) {
            'loans.queue' => $user->can('browseQueue', Loan::class),
            'loans.branch' => $user->can('browseBranch', Loan::class),
            'loans.desk' => $user->can('browseDesk', Loan::class),
            'loans.incidents.index' => $user->can('browseIncidents', LoanIncident::class),
            'loans.create' => $user->can('createLoan', Loan::class),
            default => $user->can('browseOwn', Loan::class),
        };
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'bq' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED'])],
            'borrower' => ['nullable', 'string', 'max:100'],
            'item' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            // Step 3 (FR-LN-31): only loans that are out and past their due date.
            'overdue' => ['nullable', Rule::in(['1'])],
            // ITM-2610-070: open the request form with the borrowing cart's lines filled in.
            'cart' => ['nullable', Rule::in(['1'])],
            'lab_id' => Rule::when(
                $this->user()?->hasRole('ADMIN') === true,
                ['nullable', 'integer', Rule::exists('labs', 'id')],
                [],
            ),
        ];
    }
}
