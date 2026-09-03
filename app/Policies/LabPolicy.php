<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lab;
use App\Models\User;

/** Small admin-only CRUD so goods receiving (T-022) has a real lab_id to file GRNs under. */
final class LabPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'lab.manage');
    }

    public function view(User $user, Lab $lab): bool
    {
        return $this->hasPermission($user, 'lab.manage');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'lab.manage');
    }

    public function update(User $user, Lab $lab): bool
    {
        return $this->hasPermission($user, 'lab.manage');
    }
}
