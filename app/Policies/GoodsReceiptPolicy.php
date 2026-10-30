<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

/**
 * FR-RC-01..06: receiving.manage owns the whole GRN lifecycle. The seeded SCIENTIST role no
 * longer holds receiving.manage (nor stocktake/disposal), so as seeded this is not a
 * SCIENTIST ability; access follows whichever roles are granted the permission.
 *
 * ITM-2610-042 (CEO decision): branch-scoped. A non-ADMIN holder of receiving.manage (e.g.
 * LAB_MANAGER, AUDITOR) may see, create, edit, confirm and cancel only GRNs of their own branch
 * (goods_receipts.lab_id = users.lab_id); a non-ADMIN user with no branch (lab_id null) gets
 * nothing. ADMIN keeps access to every branch (CEO decision E7: ADMIN may receive goods).
 */
final class GoodsReceiptPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'receiving.manage');
    }

    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->hasPermission($user, 'receiving.manage')
            && $this->sameBranchOrAdmin($user, (int) $goodsReceipt->lab_id);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'receiving.manage')
            && ($user->hasRole('ADMIN') || $user->lab_id !== null);
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->hasPermission($user, 'receiving.manage')
            && $goodsReceipt->status === 'DRAFT'
            && $this->sameBranchOrAdmin($user, (int) $goodsReceipt->lab_id);
    }

    private function sameBranchOrAdmin(User $user, int $labId): bool
    {
        return $user->hasRole('ADMIN') || ($user->lab_id !== null && (int) $user->lab_id === $labId);
    }
}
