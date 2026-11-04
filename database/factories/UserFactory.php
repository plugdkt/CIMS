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
        // Every unique column derives from a fresh ULID (time + 80 random bits), lowercased because the
        // DB collation compares case-insensitively. Faker's unique() was dropped: it checks in-memory and
        // case-sensitively, so values differing only by case (or a reset between tests) collided in the DB.
        $ulid = (string) Str::ulid();
        $key = strtolower($ulid);

        return [
            'ulid' => $ulid,
            'sso_subject' => 'sso_'.$key,
            'username' => 'user_'.$key,
            'email' => 'user_'.$key.'@example.test',
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
