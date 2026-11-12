<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;

/**
 * ITM-2610-051 spec section 8.1: the loan document's state machine, pure (no database), like RequisitionState.
 * Step 3 added hand-out, partial return and closing; "overdue" is not a status (BR-L08, see Loan::isOverdue()).
 *
 *   DRAFT --submit--> SUBMITTED
 *   SUBMITTED (STUDENT)  --advisorApprove--> APPROVED           (ADVISOR_APPROVED only if studentNeedsManagerStep())
 *   SUBMITTED (STUDENT)  --advisorReject---> REJECTED
 *   SUBMITTED (other) | ADVISOR_APPROVED --managerApprove--> APPROVED
 *                                        --managerReject---> REJECTED
 *   DRAFT | SUBMITTED | ADVISOR_APPROVED | APPROVED --cancel--> CANCELLED      (never after hand-out)
 *   APPROVED --handout--> ON_LOAN                                  (every line at once; no partial hand-out)
 *   ON_LOAN | PARTIALLY_RETURNED --returnPartial--> PARTIALLY_RETURNED
 *   ON_LOAN | PARTIALLY_RETURNED --close----------> CLOSED         (nothing left with the borrower, BR-L12)
 */
final class LoanState
{
    /**
     * THE ONE place the "does a student's loan still need a scientist after the advisor?" decision is read
     * (CEO decided 2026-11-05: no). Config default false: the advisor's approval alone makes the loan APPROVED;
     * true restores the old spec BR-L03 two-step path (ADVISOR_APPROVED rows are decidable either way).
     */
    public function studentNeedsManagerStep(): bool
    {
        return (bool) config('loans.student_needs_manager_step', false);
    }

    /**
     * @param  string  $status  one of the `loans.status` values (a stored string; unknown values are refused)
     * @param  string|null  $borrowerStatus  the loan's snapshot (`loans.borrower_status`)
     * @return 'DRAFT'|'SUBMITTED'|'ADVISOR_APPROVED'|'APPROVED'|'REJECTED'|'CANCELLED'|'ON_LOAN'|'PARTIALLY_RETURNED'|'CLOSED'
     *
     * @throws LoanDomainException
     */
    public function apply(string $status, string $event, ?string $borrowerStatus = null): string
    {
        $student = $borrowerStatus === 'STUDENT';

        $next = match (true) {
            $event === 'submit' && $status === 'DRAFT' => 'SUBMITTED',
            $event === 'advisorApprove' && $status === 'SUBMITTED' && $student
                => $this->studentNeedsManagerStep() ? 'ADVISOR_APPROVED' : 'APPROVED',
            $event === 'advisorReject' && $status === 'SUBMITTED' && $student => 'REJECTED',
            // A STUDENT's loan never goes to the manager straight from SUBMITTED (BR-L03 / BR-02).
            $event === 'managerApprove' && (($status === 'SUBMITTED' && ! $student) || $status === 'ADVISOR_APPROVED') => 'APPROVED',
            $event === 'managerReject' && (($status === 'SUBMITTED' && ! $student) || $status === 'ADVISOR_APPROVED') => 'REJECTED',
            $event === 'cancel' && in_array($status, ['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED'], true) => 'CANCELLED',
            $event === 'handout' && $status === 'APPROVED' => 'ON_LOAN',
            $event === 'returnPartial' && in_array($status, ['ON_LOAN', 'PARTIALLY_RETURNED'], true) => 'PARTIALLY_RETURNED',
            $event === 'close' && in_array($status, ['ON_LOAN', 'PARTIALLY_RETURNED'], true) => 'CLOSED',
            default => null,
        };

        if ($next === null) {
            throw new LoanDomainException((string) __('loans.error.invalid_transition'));
        }

        return $next;
    }
}
