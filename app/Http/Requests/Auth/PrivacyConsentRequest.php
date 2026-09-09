<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** SEC-PD-02: a logged-in user consenting to the Privacy Notice on their own behalf. */
final class PrivacyConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agree' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'agree.required' => __('privacy.consent_required'),
            'agree.accepted' => __('privacy.consent_required'),
        ];
    }
}
