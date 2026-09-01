<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Base for every Policy (SEC-AZ-02: object-level authorization on every resource).
 * `hasPermission` checks the permission catalog seeded by PermissionSeeder — the
 * same lookup Gate::before() uses for plain `$user->can('code')` ability checks, so
 * a Policy method can combine a permission check with object-level rules in one place.
 */
abstract class Policy
{
    protected function hasPermission(User $user, string $code): bool
    {
        return $user->roles->flatMap(fn ($role) => $role->permissions)->contains('code', $code);
    }

    /** SEC-AZ-04 / spec §3: the actor performing a step must not be the same user
     *  who performed an earlier step in the same workflow (e.g. issuer vs. adjustment
     *  approver). */
    protected function isDifferentActor(User $actor, ?int $otherUserId): bool
    {
        return $otherUserId === null || $actor->id !== $otherUserId;
    }
}
