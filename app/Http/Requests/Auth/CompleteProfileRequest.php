<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Models\User;
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

    /**
     * The person type this submission is really about: the stored one when it is locked
     * (see User::personTypeLocked()), otherwise what was submitted.
     */
    public function effectivePersonType(): ?string
    {
        $fixed = $this->user()?->personTypeFixed();
        if ($fixed !== null) {
            return $fixed;
        }

        $submitted = $this->input('person_type');

        return is_string($submitted) ? $submitted : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $optional = $this->phoneAndCodeOptional();
        $user = $this->user();
        $lockedType = $user?->personTypeFixed();
        $lockedAdvisor = $user !== null && $user->advisorLocked() ? (string) $user->advisor_id : null;

        return [
            // Locked after the first completed profile: no way to change it here, ADMIN included.
            'person_type' => $lockedType !== null
                ? ['nullable', Rule::in([$lockedType])]
                : ['required', Rule::in(['LECTURER', 'STAFF', 'STUDENT'])],
            'phone' => $optional
                ? ['nullable', 'string', 'regex:/^[0-9\-]{9,15}$/']
                : ['required', 'string', 'regex:/^[0-9\-]{9,15}$/'],
            'person_code' => $optional
                ? ['nullable', 'string', 'max:32']
                : ['required', 'string', 'max:32'],
            'program' => ['required', 'string', 'max:255'],
            'faculty' => ['required', 'string', 'max:255'],
            'lab_id' => $user !== null && $user->labLocked()
                // Locked once set (ITM-2610-051, BR-L05): the stored branch, or nothing, is the only accepted value.
                ? ['nullable', Rule::in([(string) $user->lab_id])]
                : [
                    'required',
                    'integer',
                    Rule::exists('labs', 'id')->where('is_active', true),
                ],
            'advisor_id' => $lockedAdvisor !== null
                // Locked once set (CEO 2026-11-02): the stored advisor, or nothing, is the only accepted value.
                ? ['nullable', Rule::in([$lockedAdvisor])]
                : [
                Rule::requiredIf(fn () => $this->effectivePersonType() === 'STUDENT'),
                'nullable',
                'integer',
                // BR-02 hardening: an advisor must really be an active advisor (holds
                // requisition.approve_advisor) and never the person filling the form in.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    $advisor = User::with('roles.permissions')->find((int) $value);
                    $self = $this->user();
                    if (
                        $advisor === null
                        || ! $advisor->is_active
                        || ($self !== null && $advisor->id === $self->id)
                        || ! $advisor->hasPermission('requisition.approve_advisor')
                    ) {
                        $fail(__('auth.validation.advisor_invalid'));
                    }
                },
                ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'person_type.required' => __('auth.validation.person_type_required'),
            'person_type.in' => $this->user()?->personTypeLocked()
                ? __('auth.validation.person_type_locked')
                : ($this->user()?->personTypeFixed() !== null
                    ? __('auth.validation.person_type_student_only')
                    : __('auth.validation.person_type_invalid')),
            'phone.required' => __('auth.validation.phone_required'),
            'phone.regex' => __('auth.validation.phone_invalid'),
            'person_code.required' => __('auth.validation.person_code_required'),
            'faculty.required' => __('auth.validation.faculty_required'),
            'lab_id.required' => __('auth.validation.lab_required'),
            'lab_id.exists' => __('auth.validation.lab_invalid'),
            'lab_id.in' => __('auth.validation.lab_locked'),
            'advisor_id.in' => __('auth.validation.advisor_locked'),
            'advisor_id.required_if' => __('auth.validation.advisor_required_for_student'),
            'advisor_id.required' => __('auth.validation.advisor_required_for_student'),
        ];
    }
}
