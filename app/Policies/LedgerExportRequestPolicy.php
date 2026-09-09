<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LedgerExportRequest;
use App\Models\User;

/**
 * NFR-02 / ST-04 (IDOR): a queued export's status/download page is addressed by its
 * own ULID, so anyone who can guess/observe another user's export URL must still be
 * refused — only the user who requested a given export may view it. This is the
 * first real target for the IDOR test T-017 deferred (see CLAUDE.md) — no prior
 * user-owned, URL-addressed resource existed to test it against.
 */
final class LedgerExportRequestPolicy extends Policy
{
    public function view(User $user, LedgerExportRequest $export): bool
    {
        return $user->id === $export->requested_by;
    }
}
