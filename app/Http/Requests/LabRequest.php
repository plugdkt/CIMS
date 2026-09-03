<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Lab;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LabRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $lab = $this->route('lab');

        if ($user === null) {
            return false;
        }

        return $lab instanceof Lab
            ? $user->can('update', $lab)
            : $user->can('create', Lab::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $lab = $this->route('lab');
        $labId = $lab instanceof Lab ? $lab->id : null;

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('labs', 'code')->ignore($labId)],
            'name_th' => ['required', 'string', 'max:255'],
            'faculty' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => __('labs.validation.code_required'),
            'code.unique' => __('labs.validation.code_unique'),
            'name_th.required' => __('labs.validation.name_th_required'),
        ];
    }
}
