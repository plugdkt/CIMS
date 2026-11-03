<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-LN-03: retire an asset: a reason and a different approver (the service also refuses while anything is out). */
final class LoanAssetRetireRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('loan_asset');

        return $asset instanceof LoanAsset && $this->user()?->can('retireAsset', $asset) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:10', 'max:500'],
            'approved_by' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required' => __('loans.error.note_too_short'),
            'note.min' => __('loans.error.note_too_short'),
            'approved_by.required' => __('loans.error.approver_invalid'),
            'approved_by.exists' => __('loans.error.approver_invalid'),
        ];
    }
}
