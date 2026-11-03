<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits of an asset's descriptive fields only (place, longest loan, replacement value, note). The branch,
 * the item, the tracking mode and every quantity are not editable here: quantities change only through the
 * ledger (adjust / retire), the others never.
 */
final class LoanAssetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('loan_asset');

        return $asset instanceof LoanAsset && $this->user()?->can('editAsset', $asset) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $asset = $this->route('loan_asset');

        return [
            'location_id' => [
                'nullable',
                'integer',
                // Only a place of the asset's own branch.
                Rule::exists('locations', 'id')->where('lab_id', $asset instanceof LoanAsset ? $asset->lab_id : 0),
            ],
            'max_loan_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'replacement_value' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'location_id.exists' => __('loans.error.location_not_in_lab'),
            'max_loan_days.integer' => __('loans.validation.days_invalid'),
            'replacement_value.numeric' => __('loans.validation.value_invalid'),
        ];
    }
}
