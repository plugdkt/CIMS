<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Requisition;
use App\Models\User;

/**
 * FR-RQ-01..05: `requisition.create` (STUDENT/STAFF/etc.) owns creating
 * and editing one's own DRAFT requisitions. `requisition.view_own` sees only requisitions
 * the user requested or advises; `requisition.view_all` (AUDITOR/LAB_MANAGER/ADMIN)
 * sees every requisition (scoped to branch for branch managers).
 */
final class RequisitionPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'requisition.view_own')
            || $this->hasPermission($user, 'requisition.view_all');
    }

    public function view(User $user, Requisition $requisition): bool
    {
        if ($this->hasPermission($user, 'requisition.view_all')) {
            if ($user->hasRole('ADMIN') || ! $user->isBranchManager()) {
                return true;
            }

            if ($requisition->lab_id === $user->lab_id) {
                return true;
            }
        }

        // User-reported 2026-09-22: requisition.view_all was removed from SCIENTIST on
        // 2026-09-21, but SCIENTIST is the only role holding requisition.issue — which left
        // the dispenser unable to open, or even list, the requisitions they are supposed to
        // dispense (the only link to the issue page lives on this very page). Whoever may
        // act on a requisition must be able to see it; this stays narrow on purpose —
        // it never exposes a DRAFT/SUBMITTED requisition still awaiting a decision.
        if ($this->canAct($user, $requisition)) {
            return true;
        }

        return $this->hasPermission($user, 'requisition.view_own')
            && ($requisition->requester_id === $user->id || $requisition->advisor_id === $user->id);
    }

    /**
     * True when this user holds an ability that actually applies to this requisition right
     * now — the shared basis for "may open it" (view) and for the dashboard's pending count.
     */
    public function canAct(User $user, Requisition $requisition): bool
    {
        return $this->issue($user, $requisition) || $this->return($user, $requisition);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'requisition.create');
    }

    public function update(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.create')
            && $requisition->requester_id === $user->id
            && $requisition->status === 'DRAFT';
    }

    public function submit(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.create')
            && $requisition->requester_id === $user->id;
    }

    /** BR-01: DRAFT | SUBMITTED can be cancelled by the requester who owns it. */
    public function cancel(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.create')
            && $requisition->requester_id === $user->id
            && in_array($requisition->status, ['DRAFT', 'SUBMITTED'], true);
    }

    /** FR-RQ-07 "ผ่านระบบ": the requisition's own advisor, logged in, deciding a SUBMITTED requisition. */
    public function advisorDecide(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.approve_advisor')
            && $requisition->advisor_id === $user->id
            && $requisition->status === 'SUBMITTED';
    }

    /**
     * FR-RQ-08: the scientist reviews a requisition once it's past the advisor step (or
     * never needed one). Whether it's actually *decidable yet* for a STUDENT requisition
     * still SUBMITTED with no advisor sign-off is BR-02's job, enforced inside
     * `ApprovalService::scientistDecide()` itself — this ability only gates who may open
     * the review action at all.
     */
    public function scientistDecide(User $user, Requisition $requisition): bool
    {
        if ($user->isBranchManager() && $requisition->lab_id !== $user->lab_id) {
            return false;
        }

        return $this->hasPermission($user, 'requisition.approve_scientist')
            && in_array($requisition->status, ['SUBMITTED', 'ADVISOR_APPROVED'], true);
    }

    /** FR-RQ-09/10: dispensing only makes sense once a requisition has been approved. */
    public function issue(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.issue')
            && $this->sharesBranch($user, $requisition)
            && in_array($requisition->status, ['APPROVED', 'PARTIALLY_ISSUED'], true);
    }

    /** FR-ST-01: returning is only meaningful once something has actually been issued. */
    public function return(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.issue')
            && $this->sharesBranch($user, $requisition)
            && in_array($requisition->status, ['PARTIALLY_ISSUED', 'ISSUED'], true);
    }

    /**
     * User-requested 2026-09-22: dispensing is branch work — a dispenser handles their own
     * branch's stock only. Checked on the abilities themselves, not just on the pages that
     * display them, so a hidden action can't still be reached by POSTing to it directly.
     *
     * A dispenser with no branch assigned matches nothing and can dispense nothing; that is
     * an account-configuration gap to fix at /admin/users, not a case to wave through.
     */
    private function sharesBranch(User $user, Requisition $requisition): bool
    {
        return $user->lab_id !== null && $requisition->lab_id === $user->lab_id;
    }
}
