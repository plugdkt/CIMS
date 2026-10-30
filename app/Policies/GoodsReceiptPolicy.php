<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

/**
 * FR-RC-01..06: receiving.manage owns the whole GRN lifecycle. The seeded SCIENTIST role no
 * longer holds receiving.manage (nor stocktake/disposal), so as seeded this is not a
 * SCIENTIST ability; access follows whichever roles are granted the permission.
 */
final class GoodsReceiptPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'receiving.manage');
    }

    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->hasPermission($user, 'receiving.manage');
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'receiving.manage');
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->hasPermission($user, 'receiving.manage') && $goodsReceipt->status === 'DRAFT';
    }
}
