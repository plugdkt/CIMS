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
        return $this->hasPermission($user, 'location.manage') && $this->inOwnLab($user, $location);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'location.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $this->hasPermission($user, 'location.manage') && $this->inOwnLab($user, $location);
    }

    /** A LAB_MANAGER only manages locations in their own branch; other `location.manage`
     *  holders (none scoped by lab today) see/edit every location, same as before. */
    private function inOwnLab(User $user, Location $location): bool
    {
        return ! $user->hasRole('LAB_MANAGER') || $location->lab_id === $user->lab_id;
    }
}
