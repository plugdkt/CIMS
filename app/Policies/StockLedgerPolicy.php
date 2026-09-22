<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** FR-LG-01: the per-item ledger page is gated on the already-seeded ledger.view permission. */
final class StockLedgerPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'ledger.view');
    }

    /** FR-LG-07: creating an adjustment is gated on ledger.adjust (warehouse managers only). */
    public function adjust(User $user): bool
    {
        return $this->hasPermission($user, 'ledger.adjust');
    }

    /**
     * Reading the adjustment history. Split from `adjust()` on 2026-09-22 (user-requested)
     * so ADMIN can reach the menu and review what was adjusted without holding a ledger
     * write permission — spec §3 keeps ADMIN off the ledger itself, so they see the list
     * but the "new adjustment" form stays gated on `adjust()`.
     */
    public function viewAdjustments(User $user): bool
    {
        return $this->adjust($user) || $user->hasRole('ADMIN');
    }
}
