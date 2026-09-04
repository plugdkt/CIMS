<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Disposal;
use App\Models\User;

/** FR-ST-05: `disposal.request` (SCIENTIST) requests; `disposal.approve` (LAB_MANAGER) approves/rejects. */
final class DisposalPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'disposal.request') || $this->hasPermission($user, 'disposal.approve');
    }

    public function view(User $user, Disposal $disposal): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'disposal.request');
    }

    public function decide(User $user, Disposal $disposal): bool
    {
        return $this->hasPermission($user, 'disposal.approve') && $disposal->status === 'PENDING';
    }
}
