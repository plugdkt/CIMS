<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** SEC-FU-01/02: extension allow-list + real MIME check (Laravel's `mimes:` rule uses finfo). */
final class UploadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof Item && $this->user()?->can('update', $item);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) (config('attachments.max_size_bytes') / 1024);
        $extensions = implode(',', config('attachments.allowed_extensions'));

        return [
            'file' => ['required', 'file', "mimes:{$extensions}", "max:{$maxKb}"],
            'doc_type' => ['required', Rule::in(['SDS', 'INVOICE', 'PHOTO', 'OTHER'])],
            'revised_date' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => __('attachments.validation.file_required'),
            'file.mimes' => __('attachments.validation.file_type'),
            'file.max' => __('attachments.validation.file_size'),
        ];
    }
}
