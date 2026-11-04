<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;

/**
 * ITM-2610-051 spec section 8.1: the loan document's state machine, pure (no database), like RequisitionState.
 * Hand-out and return transitions arrive with step 3.
 *
 *   DRAFT --submit--> SUBMITTED
 *   SUBMITTED (STUDENT)  --advisorApprove--> ADVISOR_APPROVED   (or APPROVED, see studentNeedsManagerStep())
 *   SUBMITTED (STUDENT)  --advisorReject---> REJECTED
 *   SUBMITTED (other) | ADVISOR_APPROVED --managerApprove--> APPROVED
 *                                        --managerReject---> REJECTED
 *   DRAFT | SUBMITTED | ADVISOR_APPROVED | APPROVED --cancel--> CANCELLED
 */
final class LoanState
{
    /**
     * THE ONE place the "does a student's loan still need a scientist after the advisor?" decision is read
     * (CEO answer pending, approvals.md K). Default true = spec BR-L03. Set `loans.student_needs_manager_step`
     * to false in config/loans.php and the advisor's approval alone makes the loan APPROVED.
     */
    public function studentNeedsManagerStep(): bool
    {
        return (bool) config('loans.student_needs_manager_step', true);
    }

    /**
     * @param  string  $status  one of the `loans.status` values (a stored string; unknown values are refused)
     * @param  string|null  $borrowerStatus  the loan's snapshot (`loans.borrower_status`)
     * @return 'DRAFT'|'SUBMITTED'|'ADVISOR_APPROVED'|'APPROVED'|'REJECTED'|'CANCELLED'
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
            default => null,
        };

        if ($next === null) {
            throw new LoanDomainException((string) __('loans.error.invalid_transition'));
        }

        return $next;
    }
}
