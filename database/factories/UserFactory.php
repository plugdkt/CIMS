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
        ];
    }
}
