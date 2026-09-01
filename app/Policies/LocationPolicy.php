<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

/** FR-MD-04: the location tree is managed by whoever holds location.manage (LAB_MANAGER per §3). */
final class LocationPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'location.manage');
    }

    public function view(User $user, Location $location): bool
    {
        return $this->hasPermission($user, 'location.manage');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'location.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $this->hasPermission($user, 'location.manage');
    }
}
