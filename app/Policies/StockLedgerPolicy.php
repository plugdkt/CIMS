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

    /** FR-LG-07: the adjustment list + form is gated on ledger.adjust (LAB_MANAGER only). */
    public function adjust(User $user): bool
    {
        return $this->hasPermission($user, 'ledger.adjust');
    }
}
