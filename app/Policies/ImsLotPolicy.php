<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Shared\ItemType;
use App\Models\ImsLot;
use App\Models\User;

final class ImsLotPolicy extends Policy
{
    /** `ims.view` (managers, ADMIN) and `ims.browse` (everyone else, read-only) may open the IMS stock list. */
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ims.view') || $this->hasPermission($user, 'ims.browse');
    }

    public function view(User $user, ImsLot $lot): bool
    {
        if ($this->hasPermission($user, 'ims.view')) {
            return $user->hasRole('ADMIN') || $user->lab_id === $lot->lab_id;
        }

        // Browsers see only their own branch's store.
        return $this->hasPermission($user, 'ims.browse') && $user->lab_id !== null && $user->lab_id === $lot->lab_id;
    }

    /** Purchase prices and document numbers are for `ims.view` holders only — never for browsers. */
    public function viewPrices(User $user, ImsLot $lot): bool
    {
        return $this->hasPermission($user, 'ims.view') && $this->view($user, $lot);
    }

    /** Storage location and photos of a lot — own branch's manager only (no stock movement involved). */
    public function manage(User $user, ImsLot $lot): bool
    {
        return $this->hasPermission($user, 'ims.manage') && $user->lab_id === $lot->lab_id;
    }

    /** Opening the "move by paper requisition" pages: an `ims.manage` holder with a branch (rows are then checked one by one via {@see transfer()}). */
    public function transferAny(User $user): bool
    {
        return $this->hasPermission($user, 'ims.manage') && $user->lab_id !== null;
    }

    /**
     * Cutting a lot off IMS into working stock — own branch only, only while stock remains, and (ITM-2610-062)
     * only by a manager who looks after that kind of stock (chemical / consumable; deny by default).
     */
    public function transfer(User $user, ImsLot $lot): bool
    {
        return $this->hasPermission($user, 'ims.manage')
            && $user->lab_id === $lot->lab_id
            && bccomp($lot->qty_remaining_base, '0', 6) > 0
            && $lot->item !== null
            && $user->managesItemType(ItemType::forItem($lot->item));
    }
}
