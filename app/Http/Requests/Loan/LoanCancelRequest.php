<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

/** POST /loans/{loan}/cancel: before hand-out; the reason is optional for the borrower and required for a manager (checked in the service). */
final class LoanCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('cancelLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
