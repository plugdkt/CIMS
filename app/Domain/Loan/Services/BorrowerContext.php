<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Models\User;

/**
 * ITM-2610-051 BR-L14 / BR-L05: what the system knows about the borrower, taken from the borrower's own profile
 * and never from a form: the branch (`loans.lab_id`), the status snapshot and the advisor.
 */
final class BorrowerContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?int $labId,
        /** 'LECTURER' | 'STAFF' | 'STUDENT' (person_type NULL counts as STUDENT, BR-L14) */
        public readonly string $status,
        public readonly ?int $advisorId,
    ) {
    }

    public function isStudent(): bool
    {
        return $this->status === 'STUDENT';
    }
}
