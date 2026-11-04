<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Models\Loan;
use App\Models\User;

/**
 * ITM-2610-051 (approvals.md K): the ONE definition of "who may decide / see a loan", used by LoanPolicy (what the
 * pages show), LoanApprovalService (what is enforced when the button is pressed) and LoanNotifier (who is told),
 * so the three can never disagree.
 *
 * Approver of a loan (MANAGER step) = an active holder of `loan.approve` (every scientist and warehouse manager)
 * whose `users.lab_id` equals `loans.lab_id`. The branch is checked for EVERY holder, and NULL on either side
 * matches nothing. ADMIN is not special here: a pure ADMIN does not hold `loan.approve` (PermissionSeeder), and a
 * person who is ADMIN plus scientist/manager approves only in their own branch like anyone else.
 *
 * Everything here takes the role/permission relations the User already loaded; no query is made.
 */
final class LoanAccess
{
    /** MANAGER step: may this user decide a loan of this branch? */
    public function isApproverFor(User $user, ?int $labId): bool
    {
        return $user->is_active
            && $labId !== null
            && $user->lab_id !== null
            && (int) $user->lab_id === $labId
            && $user->hasPermission('loan.approve');
    }

    /**
     * ADVISOR step: only the advisor named on THIS document, active, holding `loan.approve_advisor`, never the
     * borrower. ADMIN cannot stand in (BR-L03).
     */
    public function isAdvisorOf(User $user, Loan $loan): bool
    {
        return $loan->advisor_id !== null
            && $user->id === $loan->advisor_id
            && $user->id !== $loan->borrower_id
            && $user->is_active
            && $user->hasPermission('loan.approve_advisor');
    }

    /** Own branch (NULL matches nothing) — for the people who work the branch's loans. */
    public function sameBranch(User $user, ?int $labId): bool
    {
        return $labId !== null && $user->lab_id !== null && (int) $user->lab_id === $labId;
    }

    /** The borrower or the person who typed the request in. */
    public function isOwner(User $user, Loan $loan): bool
    {
        return $user->id === $loan->borrower_id || $user->id === $loan->created_by;
    }
}
