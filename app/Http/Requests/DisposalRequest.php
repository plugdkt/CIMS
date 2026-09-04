<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Disposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-ST-05: which container, how much, why, and how it'll be disposed of. */
final class DisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', Disposal::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', Rule::exists('containers', 'barcode')],
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', Rule::in(['EXPIRED', 'CONTAMINATED', 'DAMAGED', 'WASTE', 'OTHER'])],
            'method' => ['nullable', 'string', 'max:255'],
            'disposal_date' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required' => __('disposals.validation.barcode_required'),
            'barcode.exists' => __('disposals.validation.barcode_not_found'),
            'qty.required' => __('disposals.validation.qty_required'),
            'qty.gt' => __('disposals.validation.qty_gt'),
            'reason.required' => __('disposals.validation.reason_required'),
            'disposal_date.required' => __('disposals.validation.disposal_date_required'),
        ];
    }
}
