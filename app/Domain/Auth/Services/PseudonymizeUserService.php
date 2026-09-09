<?php

declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Models\User;

/**
 * SEC-PD-04: once a user's data retention period has passed (a written retention-period
 * policy is still an open item — see CLAUDE.md), their PII is overwritten in place rather
 * than the row deleted — every FK that references `users.id` (`stock_ledger.created_by`,
 * `requisition_items`/`requisitions.requester_id`, `issue_transactions.receiver_id`, …) is
 * `ON DELETE RESTRICT`, and those referencing rows are themselves append-only evidence
 * (AGENT RULE #6) that must keep existing — only this row's own identifying columns change.
 * `sso_subject` is deliberately left untouched: it's an opaque SSO identifier, not personal
 * data on its own, and clearing it would let a future SSO re-provision silently recreate a
 * "new" user with the same real identity.
 */
final class PseudonymizeUserService
{
    public function pseudonymize(User $user): void
    {
        $user->full_name = 'ผู้ใช้ที่ถูกลบข้อมูล #'.$user->id;
        $user->username = 'deleted-user-'.$user->id;
        $user->email = "deleted-user-{$user->id}@pseudonymized.invalid";
        $user->pos_name = null;
        $user->div_name = null;
        $user->phone_encrypted = null;
        $user->person_code_encrypted = null;
        $user->program = null;
        $user->faculty = null;
        $user->is_active = false;
        $user->pseudonymized_at = now();
        $user->save();
    }
}
