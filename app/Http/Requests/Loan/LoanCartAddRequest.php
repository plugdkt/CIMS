<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ITM-2610-070: one "add to the borrowing cart" click: which loan asset (ULID) and how many (whole number). Whether
 * the user may borrow it (own branch, ACTIVE, enough available) is decided by LoanService::assertCartLines().
 */
final class LoanCartAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('createLoan', Loan::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asset' => ['required', 'string', 'size:26', Rule::exists('loan_assets', 'ulid')],
            'qty' => ['required', 'integer', 'min:1', 'max:999999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'asset.required' => __('loans.error.asset_not_lendable'),
            'asset.size' => __('loans.error.asset_not_lendable'),
            'asset.exists' => __('loans.error.asset_not_lendable'),
            'qty.required' => __('loans.error.qty_whole'),
            'qty.integer' => __('loans.error.qty_whole'),
            'qty.min' => __('loans.error.qty_whole'),
            'qty.max' => __('loans.error.qty_whole'),
        ];
    }
}
