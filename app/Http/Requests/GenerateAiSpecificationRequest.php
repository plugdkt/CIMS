<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class GenerateAiSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', \App\Models\Item::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name_th' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'cas_no' => ['nullable', 'string', 'max:50'],
            'formula' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $th = trim((string) $this->input('name_th'));
            $en = trim((string) $this->input('name_en'));
            $cas = trim((string) $this->input('cas_no'));

            if ($th === '' && $en === '' && $cas === '') {
                $v->errors()->add('name_th', __('items.ai_spec_require_identifier'));
            }
        });
    }
}
