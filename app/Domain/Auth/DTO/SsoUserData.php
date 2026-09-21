<?php

declare(strict_types=1);

namespace App\Domain\Auth\DTO;

/** Shape of the `user` object in the MEDSCI ACC verify API response (sso_integration_guide.md §4). */
final readonly class SsoUserData
{
    public function __construct(
        public string $subject,
        public string $username,
        public string $name,
        public ?string $posName,
        public ?string $divName,
        public string $email,
    ) {
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        $username = (string) $payload['username'];
        $rawEmail = isset($payload['email']) ? trim((string) $payload['email']) : '';
        $email = ($rawEmail !== '' && $rawEmail !== '-' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL))
            ? $rawEmail
            : "{$username}@up.ac.th";

        return new self(
            subject: (string) $payload['user_id'],
            username: $username,
            name: (string) $payload['name'],
            posName: isset($payload['pos_name']) ? (string) $payload['pos_name'] : null,
            divName: isset($payload['div_name']) ? (string) $payload['div_name'] : null,
            email: $email,
        );
    }
}
