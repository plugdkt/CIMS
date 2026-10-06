<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImsReceipt;
use App\Models\User;

/**
 * IMS purchase documents. `ims.manage` (warehouse managers) writes, `ims.view` (also ADMIN)
 * reads. Writing is always limited to the actor's own branch; ADMIN holds no `ims.manage`
 * because confirming/transferring ends in ledger writes, which spec §3 keeps off ADMIN.
 */
final class ImsReceiptPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ims.view');
    }

    public function view(User $user, ImsReceipt $receipt): bool
    {
        return $this->hasPermission($user, 'ims.view') && $this->sameBranchOrAdmin($user, $receipt->lab_id);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'ims.manage') && $user->lab_id !== null;
    }

    public function update(User $user, ImsReceipt $receipt): bool
    {
        return $this->hasPermission($user, 'ims.manage')
            && $receipt->isDraft()
            && $user->lab_id === $receipt->lab_id;
    }

    private function sameBranchOrAdmin(User $user, int $labId): bool
    {
        return $user->hasRole('ADMIN') || $user->lab_id === $labId;
    }
}
