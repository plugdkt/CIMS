<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** BR-11 point 4: fields the SSO payload doesn't provide, required before creating a requisition. */
final class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 2026-11-02 (CEO): ADMIN accounts need no phone / person code. Decided from the role
     * the system assigned (roles table), never from anything the user submits.
     */
    private function phoneAndCodeOptional(): bool
    {
        $user = $this->user();

        return $user !== null && $user->hasRole('ADMIN');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $optional = $this->phoneAndCodeOptional();

        return [
            'person_type' => ['required', Rule::in(['LECTURER', 'STAFF', 'STUDENT'])],
            'phone' => $optional
                ? ['nullable', 'string', 'regex:/^[0-9\-]{9,15}$/']
                : ['required', 'string', 'regex:/^[0-9\-]{9,15}$/'],
            'person_code' => $optional
                ? ['nullable', 'string', 'max:32']
                : ['required', 'string', 'max:32'],
            'program' => ['required', 'string', 'max:255'],
            'faculty' => ['required', 'string', 'max:255'],
            'lab_id' => [
                'required',
                'integer',
                Rule::exists('labs', 'id')->where('is_active', true),
            ],
            'advisor_id' => [
                Rule::requiredIf(fn () => $this->input('person_type') === 'STUDENT'),
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'person_type.required' => __('auth.validation.person_type_required'),
            'person_type.in' => __('auth.validation.person_type_invalid'),
            'phone.required' => __('auth.validation.phone_required'),
            'phone.regex' => __('auth.validation.phone_invalid'),
            'person_code.required' => __('auth.validation.person_code_required'),
            'faculty.required' => __('auth.validation.faculty_required'),
            'lab_id.required' => __('auth.validation.lab_required'),
            'lab_id.exists' => __('auth.validation.lab_invalid'),
            'advisor_id.required_if' => __('auth.validation.advisor_required_for_student'),
            'advisor_id.exists' => __('auth.validation.advisor_invalid'),
        ];
    }
}
