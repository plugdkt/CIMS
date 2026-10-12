<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ImsLot;
use Illuminate\Foundation\Http\FormRequest;

/** SEC-FU-01/02: images only, real MIME checked by finfo, size capped by config. */
final class ImsLotPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lot = $this->route('ims_lot');

        return $lot instanceof ImsLot && $this->user()?->can('manage', $lot) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) (config('attachments.max_size_bytes') / 1024);

        return [
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', "max:{$maxKb}"],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required' => __('ims.error.photo_required'),
            'photo.mimes' => __('ims.error.photo_type'),
            'photo.max' => __('attachments.validation.file_size'),
        ];
    }
}
