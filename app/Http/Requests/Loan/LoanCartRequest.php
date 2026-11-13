<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ITM-2610-070: the borrowing cart page (GET /loans/cart) and removing a line from it. Only a holder of
 * `loan.create` (LoanPolicy::createLoan) has a cart; it is for borrowing for oneself only.
 */
final class LoanCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('createLoan', Loan::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
