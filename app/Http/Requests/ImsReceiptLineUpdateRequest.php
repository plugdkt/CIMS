<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ImsReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImsReceiptLineUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $receipt = $this->route('ims_receipt');

        return $user !== null && $receipt instanceof ImsReceipt && $user->can('update', $receipt);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lot_no' => ['nullable', 'string', 'max:64'],
            'pack_qty' => ['required', 'numeric', 'gt:0'],
            'qty' => ['nullable', 'numeric', 'gt:0', 'required_with:unit_id'],
            'unit_id' => ['nullable', 'integer', Rule::exists('units', 'id'), 'required_with:qty'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'expiry_date' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:255'],
        ];
    }
}
