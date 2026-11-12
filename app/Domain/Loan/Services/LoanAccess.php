<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Models\Loan;
use App\Models\LoanIncident;
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

    /**
     * Step 3, hand-out and return (the counter): an active holder of `loan.handout` in the loan's own branch — the
     * scientists and the warehouse managers. The branch is required of everyone (NULL matches nothing).
     *
     * A person who is ONLY an ADMIN does not hand out or take back (CEO: same rule as approving), although the ADMIN
     * role still carries `loan.handout` in PermissionSeeder (it is what lets ADMIN type a request in for a borrower,
     * FR-LN-14). So the permission must come from a role other than ADMIN: an ADMIN who is also a scientist or a
     * warehouse manager works the counter of their own branch like anyone else.
     */
    public function isHandlerFor(User $user, ?int $labId): bool
    {
        return $user->is_active
            && $this->sameBranch($user, $labId)
            && $user->roles
                ->reject(fn ($role) => $role->code === 'ADMIN')
                ->contains(fn ($role) => $role->permissions->contains('code', 'loan.handout'));
    }

    /**
     * Step 4 (BR-L11 point 2): why this user may NOT record the result of this incident — null when they may.
     * Active, holds `loan.asset_approve` (the scientists and warehouse managers; a person who is ONLY an ADMIN holds
     * none), belongs to the loan's own branch (required of everyone), and is none of: the person who recorded the
     * incident, the borrower, the person who typed the loan in for the borrower. Returns a lang key of loans.php.
     */
    public function incidentResolverProblem(User $user, Loan $loan, LoanIncident $incident): ?string
    {
        if (! $user->is_active || ! $user->hasPermission('loan.asset_approve') || ! $this->sameBranch($user, (int) $loan->lab_id)) {
            return 'loans.error.resolver_not_eligible';
        }
        if ($user->id === $incident->recorded_by) {
            return 'loans.error.resolver_is_recorder';
        }
        if ($user->id === $loan->borrower_id || $user->id === $loan->created_by) {
            return 'loans.error.resolver_is_party';
        }

        return null;
    }

    /**
     * Step 4: who may see an incident's page and open its files (photos, the outside letter). NEVER the borrower or
     * the person who typed the loan in for them, whatever else they are (CEO 2026-11-03, item 11). Otherwise: the
     * staff of the loan's own branch (counter, approvers, managers) and ADMIN (reads every branch, by role).
     */
    public function canSeeIncidentFiles(User $user, Loan $loan): bool
    {
        if (! $user->is_active || $this->isOwner($user, $loan)) {
            return false;
        }
        if ($user->hasRole('ADMIN') && $user->hasPermission('loan.view_all')) {
            return true;
        }

        return $this->sameBranch($user, (int) $loan->lab_id)
            && ($user->hasPermission('loan.view_all') || $user->hasPermission('loan.asset_approve') || $this->isHandlerFor($user, (int) $loan->lab_id));
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
