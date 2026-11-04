<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Mail\LoanAdvisorApprovalMail;
use App\Models\Loan;
use App\Models\LoanAsset;
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

    public function __construct(private readonly NotificationService $notifications)
    {
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
     * After the advisor decided: a rejection (or, with the student-step switch off, the final approval) goes to the
     * borrower; an approval that still needs a manager goes to the branch's approvers (N2).
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
