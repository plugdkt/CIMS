<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'sso_subject' => fake()->unique()->userName(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'full_name' => fake()->name(),
            'pos_name' => null,
            'div_name' => null,
            'person_type' => fake()->randomElement(['LECTURER', 'STAFF', 'STUDENT']),
            'is_active' => true,
            // Consented by default so every existing test fixture reaches whatever page
            // it's actually testing, instead of being redirected to the Privacy Notice
            // gate (EnsurePrivacyConsent) — tests that specifically exercise SEC-PD-02's
            // consent flow use the ->unconsented() state below instead.
            'privacy_consent_at' => now(),
            'privacy_consent_version' => (string) config('privacy.notice_version'),
        ];
    }

    /** @return static */
    public function unconsented()
    {
        return $this->state(fn (array $attributes) => [
            'privacy_consent_at' => null,
            'privacy_consent_version' => null,
        ]);
    }
}
