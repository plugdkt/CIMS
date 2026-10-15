<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ImsLot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImsLotGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lot = $this->route('ims_lot');

        return $lot instanceof ImsLot && $this->user()?->can('manage', $lot) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $lot = $this->route('ims_lot');
        $labId = $lot instanceof ImsLot ? $lot->lab_id : 0;

        return [
            'pack_count' => ['required', 'numeric', 'gt:0'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('lab_id', $labId)],
            'expiry_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['location_id.exists' => __('ims.error.location_other_branch')];
    }
}
