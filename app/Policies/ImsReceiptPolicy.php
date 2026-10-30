<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImsReceipt;
use App\Models\User;

/**
 * IMS purchase documents. `ims.manage` (warehouse managers) writes, `ims.view` (also ADMIN)
 * reads. Writing is always limited to the actor's own branch. ADMIN holds no `ims.manage`:
 * per the CEO's E7 decision ADMIN may receive goods on the legacy GRN path (which indirectly
 * creates RECEIVE ledger rows), but may not import/confirm IMS receipts, adjust stock, approve
 * stock-take variances or disposals, or issue/return goods.
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
