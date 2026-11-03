<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-LN-03 / BR-L13: change a POOL asset's count (ADJUST_IN / ADJUST_OUT): reason, whole quantity, a different approver. */
final class LoanAssetAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        $asset = $this->route('loan_asset');

        return $asset instanceof LoanAsset && $this->user()?->can('adjustAsset', $asset) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(['IN', 'OUT'])],
            'qty' => ['required', 'integer', 'min:1', 'max:999999'],
            'note' => ['required', 'string', 'min:10', 'max:500'],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'approved_by' => ['required', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'direction.required' => __('loans.validation.direction_required'),
            'qty.required' => __('loans.validation.qty_whole'),
            'qty.integer' => __('loans.validation.qty_whole'),
            'qty.min' => __('loans.validation.qty_whole'),
            'note.required' => __('loans.error.note_too_short'),
            'note.min' => __('loans.error.note_too_short'),
            'approved_by.required' => __('loans.error.approver_invalid'),
            'approved_by.exists' => __('loans.error.approver_invalid'),
        ];
    }
}
