<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ImsReceipt;
use Illuminate\Foundation\Http\FormRequest;

final class ImsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', ImsReceipt::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'doc_no' => ['nullable', 'string', 'max:64'],
            'fiscal_year' => ['nullable', 'integer', 'between:2500,2700'],
            'purchase_round' => ['nullable', 'string', 'max:32'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
