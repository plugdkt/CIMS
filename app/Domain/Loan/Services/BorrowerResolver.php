<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\User;

/**
 * ITM-2610-051 spec section 12: a thin seam that answers "who is borrowing, from which branch, as what". Today the
 * borrower is always a `users` row (self, or someone the counter staff types the request in for, FR-LN-14); the
 * IS-student module may later add another kind of borrower by changing only this class.
 *
 * It reads the borrower's own profile: the branch is `users.lab_id` of the BORROWER (not of whoever types), the
 * status comes from `users.person_type` (NULL = STUDENT), the advisor from `users.advisor_id`.
 */
final class BorrowerResolver
{
    public function forSelf(User $user): BorrowerContext
    {
        return $this->context($user);
    }

    /**
     * Counter staff (a holder of `loan.handout`) typing a request in for somebody else. A branch member of staff may
     * only do it for a borrower of their OWN branch; ADMIN (decided by the role, not by lab_id) for any borrower
     * who has a branch. A borrower with no branch cannot be served at all (BR-L05).
     *
     * @throws LoanDomainException
     */
    public function forOnBehalf(User $actor, int $borrowerId): BorrowerContext
    {
        $borrower = User::with('roles.permissions')->find($borrowerId);

        if (
            $borrower === null
            || ! $borrower->is_active
            || $borrower->id === $actor->id
            || ! $actor->is_active
            || ! $actor->hasPermission('loan.handout')
            || ! $borrower->hasPermission('loan.create')
        ) {
            throw new LoanDomainException((string) __('loans.error.borrower_invalid'));
        }

        if ($borrower->lab_id === null) {
            throw new LoanDomainException((string) __('loans.error.borrower_no_lab'));
        }

        if (! $actor->hasRole('ADMIN') && ($actor->lab_id === null || (int) $actor->lab_id !== (int) $borrower->lab_id)) {
            throw new LoanDomainException((string) __('loans.error.borrower_other_lab'));
        }

        return $this->context($borrower);
    }

    private function context(User $user): BorrowerContext
    {
        return new BorrowerContext(
            $user,
            $user->lab_id === null ? null : (int) $user->lab_id,
            $user->person_type ?? 'STUDENT',
            $user->advisor_id === null ? null : (int) $user->advisor_id,
        );
    }
}
