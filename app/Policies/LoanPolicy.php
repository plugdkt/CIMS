<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Loan\Services\LoanAccess;
use App\Models\Loan;
use App\Models\User;

/**
 * ITM-2610-051 step 2: who sees and acts on loan documents.
 *
 * IMPORTANT (same rule as LoanAssetPolicy): no method here is named like a permission code. AppServiceProvider's
 * Gate::before returns `true` at once when the ability equals a permission code the user holds, which would skip the
 * branch checks below. So the methods are createLoan / viewLoan / managerDecideLoan ... and call hasPermission()
 * themselves. Never ask the Gate with a loan.* permission code together with a model.
 *
 * Branch rules (BR-L05, approvals.md K): every holder of an approver / branch permission is checked against the
 * loan's `lab_id` (NULL on either side matches nothing). ADMIN sees every branch (decided by the role, not by
 * `lab_id`) but holds no approval permission, so it approves nothing. The real enforcement of the decisions is in
 * LoanApprovalService; this class decides what the pages show and which routes answer 403.
 */
final class LoanPolicy extends Policy
{
    public function __construct(private readonly LoanAccess $access)
    {
    }

    /** "Things I borrowed" page and the request form's entry. */
    public function browseOwn(User $user): bool
    {
        return $this->hasPermission($user, 'loan.view_own') || $this->hasPermission($user, 'loan.create');
    }

    public function createLoan(User $user): bool
    {
        return $this->hasPermission($user, 'loan.create');
    }

    /** Counter staff typing a request in for a borrower of their branch (ADMIN: any borrower who has a branch). */
    public function createOnBehalf(User $user): bool
    {
        return $this->hasPermission($user, 'loan.handout')
            && ($user->hasRole('ADMIN') || $user->lab_id !== null);
    }

    public function viewLoan(User $user, Loan $loan): bool
    {
        // The borrower and whoever typed it in see it in every status (including their own DRAFT).
        if ($this->access->isOwner($user, $loan)) {
            return true;
        }
        // Nobody else sees somebody's DRAFT.
        if ($loan->status === 'DRAFT') {
            return false;
        }
        // The advisor named on a STUDENT's document.
        if ($this->access->isAdvisorOf($user, $loan)) {
            return true;
        }
        // ADMIN reads every branch (loan.view_all), by role.
        if ($user->hasRole('ADMIN') && $this->hasPermission($user, 'loan.view_all')) {
            return true;
        }

        // People who work the branch's loans (approvers, managers, counter staff): own branch only.
        return $this->access->sameBranch($user, (int) $loan->lab_id)
            && (
                $this->hasPermission($user, 'loan.view_all')
                || $this->hasPermission($user, 'loan.approve')
                || $this->hasPermission($user, 'loan.view_queue')
                || $this->hasPermission($user, 'loan.handout')
            );
    }

    public function updateLoan(User $user, Loan $loan): bool
    {
        return $loan->status === 'DRAFT'
            && $this->hasPermission($user, 'loan.create')
            && $this->access->isOwner($user, $loan);
    }

    public function submitLoan(User $user, Loan $loan): bool
    {
        return $this->updateLoan($user, $loan);
    }

    /** Before hand-out: the borrower / creator, or an approver of the branch (not on somebody's DRAFT). */
    public function cancelLoan(User $user, Loan $loan): bool
    {
        if (! in_array($loan->status, Loan::CANCELLABLE, true) || ! $this->hasPermission($user, 'loan.cancel')) {
            return false;
        }

        return $this->access->isOwner($user, $loan)
            || ($loan->status !== 'DRAFT' && $this->access->isApproverFor($user, (int) $loan->lab_id));
    }

    /** ADVISOR step: only the named advisor, on a STUDENT's SUBMITTED loan. ADMIN never. */
    public function advisorDecideLoan(User $user, Loan $loan): bool
    {
        return $loan->status === 'SUBMITTED'
            && $loan->isStudentBorrower()
            && $this->access->isAdvisorOf($user, $loan);
    }

    /**
     * MANAGER step: an approver of the loan's own branch, on a loan that is waiting for one (a STUDENT's only after the
     * advisor). Not the person who typed it in for the borrower (FR-LN-14). The borrower MAY decide their own
     * (approvals.md K) — the service records that.
     */
    public function managerDecideLoan(User $user, Loan $loan): bool
    {
        if (! in_array($loan->status, Loan::AWAITING_MANAGER, true)) {
            return false;
        }
        if ($loan->isStudentBorrower() && $loan->status !== 'ADVISOR_APPROVED') {
            return false;
        }
        if ($loan->isOnBehalf() && $loan->created_by === $user->id) {
            return false;
        }

        return $this->access->isApproverFor($user, (int) $loan->lab_id);
    }

    /**
     * Step 3, the counter's queue (FR-LN-17: "waiting for hand-out" and "still out" of one's OWN branch): holders of
     * `loan.view_queue` who have a branch. Read-only by itself; acting needs handOutLoan / receiveReturnLoan.
     */
    public function browseDesk(User $user): bool
    {
        return $this->hasPermission($user, 'loan.view_queue') && $user->lab_id !== null;
    }

    /**
     * Step 3: hand out an APPROVED loan. A scientist / warehouse manager of the loan's own branch (LoanAccess::
     * isHandlerFor: no branch, another branch or an ADMIN-only account = no). The borrower may hand out their own
     * (CEO: same rule as approvals); LoanCirculationService records it as LOAN_SELF_HANDOUT.
     */
    public function handOutLoan(User $user, Loan $loan): bool
    {
        return $loan->status === 'APPROVED' && $this->access->isHandlerFor($user, (int) $loan->lab_id);
    }

    /** Step 3: take pieces back while things are out. Same people as hand-out. */
    public function receiveReturnLoan(User $user, Loan $loan): bool
    {
        return $loan->isOut() && $this->access->isHandlerFor($user, (int) $loan->lab_id);
    }

    /** Step 3 (BR-L07): the borrower / the person who typed it in asks for a later due date, while not overdue. */
    public function renewLoan(User $user, Loan $loan): bool
    {
        return $loan->isOut()
            && ! $loan->isOverdue()
            && $this->hasPermission($user, 'loan.renew')
            && $this->access->isOwner($user, $loan);
    }

    /** Step 3 (BR-L07): an approver of the loan's branch decides a waiting renewal (the 2nd and later ones). */
    public function decideRenewalLoan(User $user, Loan $loan): bool
    {
        return $loan->isOut() && $this->access->isApproverFor($user, (int) $loan->lab_id);
    }

    /** Step 3 (FR-LN-25): the printable loan slip, for whoever may open the document once it has left DRAFT. */
    public function printLoan(User $user, Loan $loan): bool
    {
        return $loan->status !== 'DRAFT' && $this->viewLoan($user, $loan);
    }

    /** The approval queue page: advisors (their students) and approvers with a branch. */
    public function browseQueue(User $user): bool
    {
        return $this->hasPermission($user, 'loan.approve_advisor')
            || ($this->hasPermission($user, 'loan.approve') && $user->lab_id !== null);
    }

    /**
     * Step 5: the loan reports and dashboard, for holders of `loan.report`. WHICH branch they read is not decided
     * here but by LoanReportScope (own branch; no branch = empty reports; ADMIN = every branch), and asking for
     * another branch is refused by LoanReportRequest. A manager without a branch may open the page and sees nothing.
     */
    public function browseReports(User $user): bool
    {
        return $this->hasPermission($user, 'loan.report');
    }

    /** "All loans of the branch" (FR-LN-31): managers (own branch) and ADMIN (every branch). */
    public function browseBranch(User $user): bool
    {
        return $this->hasPermission($user, 'loan.view_all')
            && ($user->hasRole('ADMIN') || $user->lab_id !== null);
    }
}
