<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockTake;
use App\Models\User;

/**
 * FR-ST-02/03: `stocktake.manage` (SCIENTIST per §3) creates rounds and records counts.
 * FR-ST-04: approving needs `ledger.adjust` (LAB_MANAGER) — BR-06's distinct-actor check
 * is enforced inside `StockTakeService::approve()` itself, not just here.
 */
final class StockTakePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'stocktake.manage') || $this->hasPermission($user, 'ledger.adjust');
    }

    public function view(User $user, StockTake $stockTake): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'stocktake.manage');
    }

    public function count(User $user, StockTake $stockTake): bool
    {
        return $this->hasPermission($user, 'stocktake.manage') && $stockTake->status === 'COUNTING';
    }

    public function submit(User $user, StockTake $stockTake): bool
    {
        return $this->hasPermission($user, 'stocktake.manage') && $stockTake->status === 'COUNTING';
    }

    public function approve(User $user, StockTake $stockTake): bool
    {
        return $this->hasPermission($user, 'ledger.adjust') && $stockTake->status === 'PENDING_APPROVAL';
    }

    public function cancel(User $user, StockTake $stockTake): bool
    {
        return $this->hasPermission($user, 'stocktake.manage')
            && in_array($stockTake->status, ['COUNTING', 'PENDING_APPROVAL'], true);
    }
}
