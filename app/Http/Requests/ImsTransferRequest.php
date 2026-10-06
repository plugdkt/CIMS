<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ImsLot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImsTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $lot = $this->route('ims_lot');

        return $user !== null && $lot instanceof ImsLot && $user->can('transfer', $lot);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'tracking_type' => ['required', Rule::in(['CONTAINER', 'BULK'])],
            'container_count' => ['required_if:tracking_type,CONTAINER', 'nullable', 'integer', 'min:1'],
            'qty_per_container' => ['required_if:tracking_type,CONTAINER', 'nullable', 'numeric', 'gt:0'],
            'qty' => ['required_if:tracking_type,BULK', 'nullable', 'numeric', 'gt:0'],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'ims_doc_no' => ['required', 'string', 'max:64'],
            'remark' => ['nullable', 'string', 'max:500'],
        ];
    }
}
