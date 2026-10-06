<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImsLot;
use App\Models\User;

final class ImsLotPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ims.view');
    }

    public function view(User $user, ImsLot $lot): bool
    {
        return $this->hasPermission($user, 'ims.view')
            && ($user->hasRole('ADMIN') || $user->lab_id === $lot->lab_id);
    }

    /** Cutting a lot off IMS into working stock — own branch only, and only while stock remains. */
    public function transfer(User $user, ImsLot $lot): bool
    {
        return $this->hasPermission($user, 'ims.manage')
            && $user->lab_id === $lot->lab_id
            && bccomp($lot->qty_remaining_base, '0', 6) > 0;
    }
}
