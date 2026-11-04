<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

/** POST /loans/{loan}/submit: no input, authorization only (the borrower / creator of a DRAFT). */
final class LoanSubmitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $this->user() !== null && $loan instanceof Loan && $this->user()->can('submitLoan', $loan);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
