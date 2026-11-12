<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Loan\Services\LoanAccess;
use App\Models\Loan;
use App\Models\LoanIncident;
use App\Models\User;

/**
 * ITM-2610-051 step 4: who sees and acts on damage / loss cases.
 *
 * Same rule as LoanPolicy: no method is named like a permission code (Gate::before would answer `true` for a holder
 * and skip the object checks). The rules themselves live in LoanAccess, shared with LoanIncidentService and the
 * ledger, which enforce them again when the button is pressed.
 *
 * `view` is also the ability the generic /attachments/{attachment}/download route asks of an attachment's owner, so
 * it MUST stay closed to the borrower and to the person who typed the loan in for them (CEO 2026-11-03, item 11:
 * the borrower never opens the files of an incident, their own included).
 */
final class LoanIncidentPolicy extends Policy
{
    public function __construct(private readonly LoanAccess $access)
    {
    }

    /** The list of the branch's incidents: staff with a branch (counter, approvers, managers) and ADMIN (all branches). */
    public function browseIncidents(User $user): bool
    {
        if ($user->hasRole('ADMIN') && $this->hasPermission($user, 'loan.view_all')) {
            return true;
        }

        return $user->lab_id !== null
            && (
                $this->hasPermission($user, 'loan.view_all')
                || $this->hasPermission($user, 'loan.asset_approve')
                || $this->access->isHandlerFor($user, (int) $user->lab_id)
            );
    }

    /** The incident's page and its files. Never the borrower / the on-behalf creator. */
    public function view(User $user, LoanIncident $incident): bool
    {
        $loan = $incident->loan;

        return $loan instanceof Loan && $this->access->canSeeIncidentFiles($user, $loan);
    }

    /**
     * Attach a photo or the outside letter while the incident is PENDING: the counter of the loan's branch (the
     * person who recorded it included) and whoever may record its result. Attaching does not show any file.
     */
    public function attachFile(User $user, LoanIncident $incident): bool
    {
        $loan = $incident->loan;
        if (! $loan instanceof Loan || ! $incident->isPending()) {
            return false;
        }

        return $this->access->isHandlerFor($user, (int) $loan->lab_id)
            || $this->access->incidentResolverProblem($user, $loan, $incident) === null;
    }

    /**
     * Record the result: a holder of `loan.asset_approve` of the loan's own branch who is not the recorder, not the
     * borrower and not the on-behalf creator. An ADMIN-only account cannot.
     */
    public function resolveIncident(User $user, LoanIncident $incident): bool
    {
        $loan = $incident->loan;

        return $loan instanceof Loan
            && $incident->isPending()
            && $this->access->incidentResolverProblem($user, $loan, $incident) === null;
    }
}
