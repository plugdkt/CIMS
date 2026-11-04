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

    /** The warehouse side ("จัดการใบเบิก"): managers who see every requisition, and dispensers. */
    public function manage(User $user): bool
    {
        return $this->hasPermission($user, 'requisition.view_all')
            || $this->hasPermission($user, 'requisition.approve_scientist')
            || $this->hasPermission($user, 'requisition.issue');
    }

    public function view(User $user, Requisition $requisition): bool
    {
        if ($this->hasPermission($user, 'requisition.view_all')) {
            if ($user->hasRole('ADMIN') || ! $user->isBranchManager()) {
                return true;
            }

            // Phase 3: a warehouse manager sees their own branch's requisitions of the kinds they
            // are responsible for (a legacy mixed one if they hold either kind).
            if ($requisition->lab_id === $user->lab_id && $this->managesKinds($user, $requisition, false)) {
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

        // approvals.md K (2026-11-04): an approver (every scientist of the branch, not only the
        // dispensing stage) must open the requisitions waiting for them, and see how their own
        // decisions ended. Own branch only (a user with no branch sees nothing) and never a DRAFT,
        // which is still the requester's private work in progress.
        // A warehouse manager is still narrowed by kind here (phase 3: see with either kind of a mixed one;
        // none held = nothing); scientists are not (managesKinds() only narrows branch managers).
        if (
            $this->approvesInBranch($user, $requisition)
            && $requisition->status !== 'DRAFT'
            && $this->managesKinds($user, $requisition, false)
        ) {
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
     * FR-RQ-08 + BR-02 (restored by CEO decision 2026-10-30, ITM-2610-046): the scientist
     * reviews an ADVISOR_APPROVED requisition, or a SUBMITTED one whose requester is not a
     * STUDENT. A STUDENT's SUBMITTED requisition is still waiting for the advisor, so no
     * decision form is offered yet. This ability gates who may open the review action.
     */
    public function scientistDecide(User $user, Requisition $requisition): bool
    {
        // approvals.md K (CEO, 2026-11-04): every scientist of the requisition's branch may decide it, and a
        // warehouse manager too. The branch is checked for EVERY holder of the permission, not just for
        // branch managers (a scientist used to skip that check). No branch on the account = no decision;
        // ADMIN holds no `requisition.approve_scientist`, so it never gets here.
        if (! $this->approvesInBranch($user, $requisition)) {
            return false;
        }

        // Phase 3: a warehouse manager decides only the kinds they are responsible for (a mixed legacy one
        // needs both); a scientist is not narrowed by kind (managesKinds() only narrows branch managers).
        if (! $this->managesKinds($user, $requisition, true)) {
            return false;
        }

        if ($requisition->status === 'ADVISOR_APPROVED') {
            return true;
        }

        return $requisition->status === 'SUBMITTED' && $requisition->requester_status !== 'STUDENT';
    }

    /** FR-RQ-09/10: dispensing only makes sense once a requisition has been approved. */
    public function issue(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.issue')
            && $this->sharesBranch($user, $requisition)
            && $this->managesKinds($user, $requisition, true)
            && in_array($requisition->status, ['APPROVED', 'PARTIALLY_ISSUED'], true);
    }

    /** FR-ST-01: returning is only meaningful once something has actually been issued. */
    public function return(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.issue')
            && $this->sharesBranch($user, $requisition)
            && $this->managesKinds($user, $requisition, true)
            && in_array($requisition->status, ['PARTIALLY_ISSUED', 'ISSUED'], true);
    }

    /**
     * Holds `requisition.approve_scientist` and belongs to this requisition's branch. `lab_id` is compared
     * with a null guard on both sides, so a missing branch can never "match" by NULL === NULL.
     */
    private function approvesInBranch(User $user, Requisition $requisition): bool
    {
        return $this->hasPermission($user, 'requisition.approve_scientist') && $this->sharesBranch($user, $requisition);
    }

    /**
     * ITM-2610-052 phase 3: does this warehouse manager hold the kinds of stock in the requisition?
     * `$all` = every kind (deciding), otherwise any kind (viewing). People who are not branch
     * managers (ADMIN, dispensers, requesters) are not narrowed by kind here.
     */
    private function managesKinds(User $user, Requisition $requisition, bool $all): bool
    {
        if (! $user->isBranchManager()) {
            return true;
        }

        $kinds = $requisition->itemKinds();
        $managed = $user->managedItemTypes();
        $held = array_intersect($kinds, $managed);

        // No kind at all (no lines and an empty request_type) matches nobody, as in the queue scope.
        return $kinds !== [] && ($all ? count($held) === count($kinds) : $held !== []);
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
