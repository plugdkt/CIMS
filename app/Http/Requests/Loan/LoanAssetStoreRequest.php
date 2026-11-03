<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ITM-2610-051 FR-LN-01: register an asset. A branch manager is tied to their own branch (a `lab_id` of
 * another branch is refused); ADMIN must choose the branch (BR-L05). Quantities are whole numbers.
 * The rules that need the database (item counted in pieces, POOL already registered, location of that
 * branch) are enforced again, authoritatively, in LoanLedgerService.
 */
final class LoanAssetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('registerAsset', LoanAsset::class) === true;
    }

    /** The branch this registration is for: the manager's own, or the one ADMIN chose. */
    public function labId(): ?int
    {
        $user = $this->user();
        if ($user === null) {
            return null;
        }

        if ($user->hasRole('ADMIN')) {
            return $this->filled('lab_id') ? (int) $this->input('lab_id') : null;
        }

        return $user->lab_id !== null ? (int) $user->lab_id : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isUnit = $this->input('tracking_mode') === 'UNIT';

        return [
            'lab_id' => ['nullable', 'integer', Rule::exists('labs', 'id')->where('is_active', true)],
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('is_active', true)],
            'tracking_mode' => ['required', Rule::in(['UNIT', 'POOL'])],
            'qty' => $isUnit ? ['nullable', 'integer', 'in:1'] : ['required', 'integer', 'min:1', 'max:999999'],
            'asset_tag' => ['nullable', 'string', 'max:64', Rule::unique('loan_assets', 'asset_tag')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'max_loan_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'replacement_value' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'condition_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            $labId = $this->labId();

            if ($labId === null) {
                $validator->errors()->add('lab_id', __('loans.validation.lab_required'));

                return;
            }

            // A branch manager may not register into another branch, whatever they submit.
            if ($user !== null && ! $user->hasRole('ADMIN') && $this->filled('lab_id') && (int) $this->input('lab_id') !== $labId) {
                $validator->errors()->add('lab_id', __('loans.validation.lab_not_own_branch'));
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'item_id.required' => __('loans.validation.item_required'),
            'item_id.exists' => __('loans.validation.item_required'),
            'tracking_mode.required' => __('loans.validation.mode_required'),
            'qty.required' => __('loans.validation.qty_whole'),
            'qty.integer' => __('loans.validation.qty_whole'),
            'qty.min' => __('loans.validation.qty_whole'),
            'qty.in' => __('loans.validation.unit_qty_one'),
            'asset_tag.unique' => __('loans.error.tag_exists'),
            'max_loan_days.integer' => __('loans.validation.days_invalid'),
            'replacement_value.numeric' => __('loans.validation.value_invalid'),
        ];
    }
}
