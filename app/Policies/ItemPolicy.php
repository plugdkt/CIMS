<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

/** FR-MD-01/07: item.view sees the registry, item.manage can create/edit (LAB_MANAGER per §3). */
final class ItemPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'item.view');
    }

    public function view(User $user, Item $item): bool
    {
        return $this->hasPermission($user, 'item.view');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'item.manage');
    }

    public function update(User $user, Item $item): bool
    {
        return $this->hasPermission($user, 'item.manage');
    }
}
