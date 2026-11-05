<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ImsLot;
use Illuminate\Foundation\Http\FormRequest;

/** Asks whether a paper requisition number was already used in the user's branch (a warning only, never a block). */
final class ImsTransferDocCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('transferAny', ImsLot::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ims_doc_no' => ['required', 'string', 'max:64'],
            'lots' => ['nullable', 'array', 'max:100'],
            'lots.*' => ['string', 'size:26'],
        ];
    }
}
