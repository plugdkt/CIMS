<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Requisition\Services\ShopAvailability;
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

    /**
     * ITM-2610-062: whoever may shop (requisition.create, with a branch) may ask for working stock to be refilled for an
     * item they can see on the shop shelf — active, and with something in their own branch's IMS store.
     */
    public function requestRefill(User $user, Item $item): bool
    {
        if (! $this->hasPermission($user, 'requisition.create') || $user->lab_id === null || ! $item->is_active) {
            return false;
        }

        return bccomp(app(ShopAvailability::class)->forItem($item, (int) $user->lab_id)['ims'], '0', 6) > 0;
    }
}
