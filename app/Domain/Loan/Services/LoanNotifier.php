<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Mail\LoanAdvisorApprovalMail;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanDueChange;
use App\Models\LoanIncident;
use App\Models\LoanMovement;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * ITM-2610-051: notifications of the lending module (a class of its own; NotificationService is only used,
 * not changed). Called by the controller AFTER the ledger write has succeeded, so a mail or database problem
 * here can never undo or block the recorded movement: every failure is caught and logged.
 *
 * Step 1: the person named as approver of an ADJUST / RETIRE is told, in the system and by
 * email. The text says plainly that being named is NOT a confirmation (a real confirm step by the named
 * person is still open) and tells them whom to contact if they know nothing about it.
 * Step 2: the request / decision notices N1-N3 (see the methods below). Only a reference
 * the receiver already sees in the system is included: the actor's display name, the item, the branch and
 * the reason; no phone, code or other personal data.
 */
final class LoanNotifier
{
    public const TYPE_APPROVER_NAMED = 'loan.approver_named';

    public const TYPE_PENDING_ADVISOR = 'loan.pending_advisor';

    public const TYPE_PENDING_MANAGER = 'loan.pending_manager';

    public const TYPE_DECISION = 'loan.decision';

    public const TYPE_CANCELLED = 'loan.cancelled';

    public const TYPE_NO_APPROVER = 'loan.no_approver';

    public const TYPE_READY_HANDOUT = 'loan.ready_handout';

    public const TYPE_RENEW_PENDING = 'loan.renew_pending';

    public const TYPE_RENEW_DECISION = 'loan.renew_decision';

    public const TYPE_INCIDENT_PENDING = 'loan.incident_pending';

    public const TYPE_WRITE_OFF = 'loan.write_off';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LoanAccess $access,
    ) {
    }

    /**
     * Step 3: a loan became APPROVED, so the counter of its branch is told it can be handed out. Same channel rule
     * as every branch notice: in the system to every scientist and warehouse manager of the branch (the people
     * LoanAccess::isHandlerFor accepts), email to the warehouse managers only. Not to the person who just approved
     * it (they know) and not to the borrower (who gets the "approved" notice). Document number and pick-up date only.
     */
    public function readyForHandout(Loan $loan, User $actor): void
    {
        try {
            if ($loan->status !== 'APPROVED') {
                return;
            }

            $title = (string) __('loans.notify.ready_handout_title');
            $body = (string) __('loans.notify.ready_handout_body', ['doc_no' => $loan->doc_no, 'date' => $loan->loan_date->format('d/m/Y')]);
            $link = route('loans.handout.form', $loan);

            foreach ($this->branchUsersHolding('loan.handout', $loan) as $handler) {
                if ($handler->id === $actor->id || $handler->id === $loan->borrower_id || ! $this->access->isHandlerFor($handler, (int) $loan->lab_id)) {
                    continue;
                }
                if ($handler->isBranchManager()) {
                    $this->notifications->notifyInAppAndEmail($handler, self::TYPE_READY_HANDOUT, $title, $body, $link);
                } else {
                    $this->notifications->notifyInApp($handler, self::TYPE_READY_HANDOUT, $title, $body, $link);
                }
            }
        } catch (Throwable $e) {
            Log::warning("Failed to notify the counter about approved loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * Step 3 (BR-L07): a renewal was asked for. An automatic one (the first) needs nobody; a PENDING one goes to the
     * approvers of the loan's branch — in the system to all of them, email to the warehouse managers only — except
     * the person who asked.
     */
    public function renewalRequested(Loan $loan, LoanDueChange $change, User $actor): void
    {
        try {
            if ($change->status !== 'PENDING') {
                return;
            }

            $title = (string) __('loans.notify.renew_pending_title');
            $body = (string) __('loans.notify.renew_pending_body', ['doc_no' => $loan->doc_no, 'due' => $change->new_due_date->format('d/m/Y')]);
            $link = route('loans.show', $loan);

            foreach ($this->branchUsersHolding('loan.approve', $loan) as $approver) {
                if ($approver->id === $actor->id || ! $this->access->isApproverFor($approver, (int) $loan->lab_id)) {
                    continue;
                }
                if ($approver->isBranchManager()) {
                    $this->notifications->notifyInAppAndEmail($approver, self::TYPE_RENEW_PENDING, $title, $body, $link);
                } else {
                    $this->notifications->notifyInApp($approver, self::TYPE_RENEW_PENDING, $title, $body, $link);
                }
            }
        } catch (Throwable $e) {
            Log::warning("Failed to notify about the renewal request on loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /** Step 3: the result of a renewal request goes to the borrower (not to themselves when they decided it). */
    public function renewalDecided(Loan $loan, LoanDueChange $change, User $actor): void
    {
        try {
            $borrower = User::find($loan->borrower_id);
            if ($borrower === null || $borrower->id === $actor->id || ! $borrower->is_active) {
                return;
            }

            $approved = $change->status === 'APPROVED';
            $this->notifications->notifyInAppAndEmail(
                $borrower,
                self::TYPE_RENEW_DECISION,
                (string) __($approved ? 'loans.notify.renew_approved_title' : 'loans.notify.renew_rejected_title', ['doc_no' => $loan->doc_no]),
                (string) __($approved ? 'loans.notify.renew_approved_body' : 'loans.notify.renew_rejected_body', [
                    'doc_no' => $loan->doc_no,
                    'due' => $loan->due_date->format('d/m/Y'),
                ]),
                route('loans.show', $loan),
            );
        } catch (Throwable $e) {
            Log::warning("Failed to notify about the renewal decision on loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * Step 4: damaged / lost pieces were recorded on a return, so a result has to be recorded. Told: the people of
     * the loan's branch who MAY record it (LoanAccess::incidentResolverProblem: `loan.asset_approve`, not the
     * recorder, not the borrower, not the on-behalf creator) — in the system to all of them (the scientists
     * included), email to the warehouse managers only. Document number and date only: no name, no description.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LoanIncident>  $incidents  the ones this return wrote
     */
    public function incidentRecorded(Loan $loan, \Illuminate\Database\Eloquent\Collection $incidents, User $actor): void
    {
        try {
            $first = $incidents->first();
            if ($first === null) {
                return;
            }

            $title = (string) __('loans.notify.incident_pending_title');
            $body = (string) __('loans.notify.incident_pending_body', ['doc_no' => $loan->doc_no, 'date' => now()->format('d/m/Y')]);
            $link = $incidents->count() === 1 ? route('loans.incidents.show', $first) : route('loans.incidents.index');

            foreach ($this->branchUsersHolding('loan.asset_approve', $loan) as $resolver) {
                if ($resolver->id === $actor->id || $this->access->incidentResolverProblem($resolver, $loan, $first) !== null) {
                    continue;
                }
                if ($resolver->isBranchManager()) {
                    $this->notifications->notifyInAppAndEmail($resolver, self::TYPE_INCIDENT_PENDING, $title, $body, $link);
                } else {
                    $this->notifications->notifyInApp($resolver, self::TYPE_INCIDENT_PENDING, $title, $body, $link);
                }
            }
        } catch (Throwable $e) {
            Log::warning("Failed to notify about incidents on loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * FR-LN-N9: pieces were WRITTEN OFF as the result of an incident -> every ADMIN, in the system and by email, so
     * somebody outside the branch always learns of it (the control against two people agreeing, spec R10).
     * Document number and date only; who recorded and who decided are on the incident page and in the audit log.
     */
    public function writtenOff(LoanIncident $incident): void
    {
        try {
            $loan = $incident->loan;
            if ($loan === null) {
                return;
            }

            $title = (string) __('loans.notify.write_off_title');
            $body = (string) __('loans.notify.write_off_body', ['doc_no' => $loan->doc_no, 'date' => now()->format('d/m/Y')]);

            foreach ($this->notifications->usersWithRole('ADMIN') as $admin) {
                // An ADMIN who is the borrower / on-behalf creator cannot open the incident page: link the document.
                $link = $this->access->isOwner($admin, $loan) ? route('loans.show', $loan) : route('loans.incidents.show', $incident);
                $this->notifications->notifyInAppAndEmail($admin, self::TYPE_WRITE_OFF, $title, $body, $link);
            }
        } catch (Throwable $e) {
            Log::warning("Failed to notify ADMIN about the write-off of incident #{$incident->id}: {$e->getMessage()}");
        }
    }

    /**
     * FR-LN-N9, the other half: the count of an asset that was EVER lent was adjusted down (ADJUST_OUT) -> every
     * ADMIN. Nothing is sent for an asset that never left the shelf. Date only; the rest is on the asset's page.
     */
    public function adjustedOut(LoanAsset $asset): void
    {
        try {
            if (! LoanMovement::where('loan_asset_id', $asset->id)->where('movement_type', 'HANDOUT')->exists()) {
                return;
            }

            $title = (string) __('loans.notify.adjust_out_title');
            $body = (string) __('loans.notify.adjust_out_body', ['date' => now()->format('d/m/Y')]);

            foreach ($this->notifications->usersWithRole('ADMIN') as $admin) {
                $this->notifications->notifyInAppAndEmail($admin, self::TYPE_WRITE_OFF, $title, $body, route('loans.assets.show', $asset));
            }
        } catch (Throwable $e) {
            Log::warning("Failed to notify ADMIN about the adjust-out of asset #{$asset->id}: {$e->getMessage()}");
        }
    }

    /**
     * Active users of the loan's own branch whose roles carry this permission (branch required; the caller still
     * applies the LoanAccess rule, which is the one that decides).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function branchUsersHolding(string $permission, Loan $loan): \Illuminate\Database\Eloquent\Collection
    {
        return User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('code', $permission))
            ->where('is_active', true)
            ->whereNotNull('lab_id')
            ->where('lab_id', $loan->lab_id)
            ->with('roles.permissions')
            ->get();
    }

    /**
     * Step 2 (spec section 10, N1-N3). Every method is called by the controller AFTER the decision is committed and
     * swallows its own failures. No message carries a phone, a student code or any personal detail: only the
     * document number, the dates and (in the advisor's mail) what is asked for.
     */

    /** N1 / N2: a request was submitted: a STUDENT's goes to the advisor, anybody else's to the branch's approvers. */
    public function submitted(Loan $loan): void
    {
        try {
            if ($loan->isStudentBorrower()) {
                $this->toAdvisor($loan);
            } else {
                $this->toApprovers($loan);
            }
        } catch (Throwable $e) {
            Log::warning("Failed to notify about submitted loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * After the advisor decided: a rejection or the final approval (APPROVED, the CEO's 2026-11-05 rule) goes to the
     * borrower; no "waiting for the branch" notice (N2) and no notice to the scientist yet (hand-out is step 3).
     * Only when the old switch is on (ADVISOR_APPROVED) does the branch's approvers get N2.
     *
     * @param  'APPROVE'|'REJECT'  $decision
     */
    public function advisorDecided(Loan $loan, string $decision, User $actor): void
    {
        try {
            if ($decision === 'APPROVE' && $loan->status === 'ADVISOR_APPROVED') {
                $this->toApprovers($loan);

                return;
            }
            $this->toBorrower($loan, $decision, $actor);
        } catch (Throwable $e) {
            Log::warning("Failed to notify about advisor decision on loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * N3: the manager's result to the borrower (not to themselves when they decided their own request).
     *
     * @param  'APPROVE'|'REJECT'  $decision
     */
    public function managerDecided(Loan $loan, string $decision, User $actor): void
    {
        try {
            $this->toBorrower($loan, $decision, $actor);
        } catch (Throwable $e) {
            Log::warning("Failed to notify about manager decision on loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /** A manager (not the borrower) cancelled the request: tell the borrower. */
    public function cancelled(Loan $loan, User $actor): void
    {
        try {
            $borrower = User::find($loan->borrower_id);
            if ($borrower === null || $borrower->id === $actor->id || ! $borrower->is_active) {
                return;
            }

            $this->notifications->notifyInAppAndEmail(
                $borrower,
                self::TYPE_CANCELLED,
                (string) __('loans.notify.cancelled_title', ['doc_no' => $loan->doc_no]),
                (string) __('loans.notify.cancelled_body', ['doc_no' => $loan->doc_no]),
                route('loans.show', $loan),
            );
        } catch (Throwable $e) {
            Log::warning("Failed to notify about cancelled loan #{$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * Who is told a loan waits for a manager: every active holder of `loan.approve` in the loan's own branch (the
     * same rule as LoanAccess::isApproverFor, branch required), except the borrower. In-system to all, email only to
     * the warehouse managers (the pattern of requisitions); nobody qualifies -> every ADMIN is told to fix the roster.
     */
    private function toApprovers(Loan $loan): void
    {
        $approvers = User::query()
            ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'loan.approve'))
            ->where('is_active', true)
            ->whereNotNull('lab_id')
            ->where('lab_id', $loan->lab_id)
            ->with('roles')
            ->get();

        $link = route('loans.show', $loan);

        if ($approvers->isEmpty()) {
            foreach ($this->notifications->usersWithRole('ADMIN') as $admin) {
                $this->notifications->notifyInAppAndEmail(
                    $admin,
                    self::TYPE_NO_APPROVER,
                    (string) __('loans.notify.no_approver_title'),
                    (string) __('loans.notify.no_approver_body', ['doc_no' => $loan->doc_no, 'lab' => (string) $loan->lab?->name_th]),
                    $link,
                );
            }

            return;
        }

        $title = (string) __('loans.notify.pending_manager_title');
        $body = (string) __('loans.notify.pending_manager_body', ['doc_no' => $loan->doc_no, 'due' => $loan->due_date->format('d/m/Y')]);

        foreach ($approvers as $approver) {
            if ($approver->id === $loan->borrower_id) {
                continue;
            }
            if ($approver->isBranchManager()) {
                $this->notifications->notifyInAppAndEmail($approver, self::TYPE_PENDING_MANAGER, $title, $body, $link);
            } else {
                $this->notifications->notifyInApp($approver, self::TYPE_PENDING_MANAGER, $title, $body, $link);
            }
        }
    }

    /** N1: the advisor gets the 72-hour signed link by email (items and dates only) and an in-system notice. */
    private function toAdvisor(Loan $loan): void
    {
        $advisor = $loan->advisor_id === null ? null : User::find($loan->advisor_id);
        if ($advisor === null || ! $advisor->is_active) {
            return;
        }

        $loan->loadMissing('lines.asset.item');

        if (! empty($advisor->email) && filter_var($advisor->email, FILTER_VALIDATE_EMAIL) !== false) {
            $signedUrl = URL::temporarySignedRoute(
                'loans.approve.signed',
                now()->addHours((int) config('loans.advisor_link_hours', 72)),
                ['loan' => $loan->ulid],
            );

            try {
                Mail::to($advisor->email)->send(new LoanAdvisorApprovalMail($loan, $signedUrl, (string) $advisor->full_name));
            } catch (Throwable $e) {
                Log::warning("Failed to send advisor approval email for loan {$loan->doc_no}: {$e->getMessage()}");
            }
        }

        // In-system only: the email above already is the "Email" half of N1 (no second email, like requisitions).
        $this->notifications->notifyInApp(
            $advisor,
            self::TYPE_PENDING_ADVISOR,
            (string) __('loans.notify.pending_advisor_title'),
            (string) __('loans.notify.pending_advisor_body', ['doc_no' => $loan->doc_no]),
            route('loans.show', $loan),
        );
    }

    /**
     * @param  'APPROVE'|'REJECT'  $decision
     */
    private function toBorrower(Loan $loan, string $decision, User $actor): void
    {
        $borrower = User::find($loan->borrower_id);
        if ($borrower === null || $borrower->id === $actor->id || ! $borrower->is_active) {
            return;
        }

        $this->notifications->notifyInAppAndEmail(
            $borrower,
            self::TYPE_DECISION,
            (string) __($decision === 'APPROVE' ? 'loans.notify.approved_title' : 'loans.notify.rejected_title', ['doc_no' => $loan->doc_no]),
            (string) __($decision === 'APPROVE' ? 'loans.notify.approved_body' : 'loans.notify.rejected_body', ['doc_no' => $loan->doc_no]),
            route('loans.show', $loan),
        );
    }

    /**
     * @param  'adjust_in'|'adjust_out'|'retire'  $action
     */
    public function approverNamed(User $actor, LoanAsset $asset, string $action, int $approverId, string $reason): void
    {
        try {
            $approver = User::find($approverId);
            // Never to the person who did it (the ledger already forbids that), nor to an account that cannot take it.
            if ($approver === null || $approver->id === $actor->id || ! $approver->is_active) {
                return;
            }

            $asset->loadMissing(['item', 'lab']);

            $this->notifications->notifyInAppAndEmail(
                $approver,
                self::TYPE_APPROVER_NAMED,
                (string) __('loans.notify.approver_named_title'),
                (string) __('loans.notify.approver_named_body', [
                    'actor' => $actor->full_name,
                    'action' => (string) __('loans.notify.action_'.$action),
                    'item' => (string) $asset->item?->name_th,
                    'lab' => (string) $asset->lab?->name_th,
                    'reason' => Str::limit(trim($reason), 200),
                ]),
                route('loans.assets.show', $asset),
            );
        } catch (Throwable $e) {
            Log::warning("Failed to notify the named loan approver for asset #{$asset->id}: {$e->getMessage()}");
        }
    }
}
