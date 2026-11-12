<?php

declare(strict_types=1);

namespace App\Domain\Loan\Reports;

use App\Models\User;

/**
 * ITM-2610-051 step 5: whose data a loan report / dashboard may read — decided ONCE, from the user, and then used
 * by every query of LoanReportService and LoanDashboardService.
 *
 *  - ADMIN holding `loan.report`: every branch, or the one branch they picked.
 *  - Anybody else holding `loan.report` (the warehouse managers): their own branch only. With no branch: nothing
 *    (an empty report, not an error).
 * Asking for ANOTHER branch is refused with 403 before this is built (LoanReportRequest::authorize()).
 *
 * `canSeeValue`: prices / values are for holders of `loan.asset_manage` only.
 */
final class LoanReportScope
{
    private function __construct(
        public readonly bool $allBranches,
        public readonly ?int $labId,
        public readonly int $viewerId,
        public readonly bool $canSeeValue,
    ) {
    }

    public static function forUser(User $user, ?int $requestedLabId): self
    {
        $allBranches = self::seesAllBranches($user);

        return new self(
            allBranches: $allBranches,
            labId: $allBranches ? $requestedLabId : ($user->lab_id === null ? null : (int) $user->lab_id),
            viewerId: $user->id,
            canSeeValue: $user->hasPermission('loan.asset_manage'),
        );
    }

    /** The one definition of "reads every branch" for the loan reports (by role, like LoanPolicy::viewLoan). */
    public static function seesAllBranches(User $user): bool
    {
        return $user->hasRole('ADMIN') && $user->hasPermission('loan.report');
    }

    /**
     * The `lab_id` every query must be narrowed to: null = no narrowing (ADMIN, every branch); otherwise that
     * branch. 0 matches no branch at all (a manager without a branch).
     */
    public function labFilter(): ?int
    {
        if ($this->allBranches) {
            return $this->labId;
        }

        return $this->labId ?? 0;
    }

    /** For the audit row: "all" or the branch id. */
    public function describe(): string
    {
        $lab = $this->labFilter();

        return $lab === null ? 'all' : (string) $lab;
    }
}
