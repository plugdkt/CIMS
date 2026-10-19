<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Put several IMS lots in one storage place at once — own branch's manager, own branch's place and lots only. */
final class ImsLotLocateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('ims.manage') && $user->lab_id !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $labId = $this->user()->lab_id ?? 0;

        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('lab_id', $labId)],
            'lots' => ['required', 'array', 'min:1'],
            'lots.*' => ['integer', Rule::exists('ims_lots', 'id')->where('lab_id', $labId)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'location_id.required' => __('ims.locate_pick_place'),
            'location_id.exists' => __('ims.error.location_other_branch'),
            'lots.required' => __('ims.locate_pick_lots'),
            'lots.min' => __('ims.locate_pick_lots'),
        ];
    }
}
