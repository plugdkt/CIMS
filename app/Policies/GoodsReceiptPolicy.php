<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;

/** FR-RC-01..06: receiving.manage (SCIENTIST per §3) owns the whole GRN lifecycle. */
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
