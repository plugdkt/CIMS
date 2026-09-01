<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** FR-AU-07: only ADMIN assigns/removes roles or toggles is_active (BR-11 point 5). */
final class UserPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'user.manage');
    }

    public function manageRoles(User $actor, User $target): bool
    {
        return $this->hasPermission($actor, 'user.manage');
    }
}
