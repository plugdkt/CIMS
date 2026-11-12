<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanDueChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 step 3 (BR-L07, FR-LN-15): moving a loan's due date.
 *
 *  - Who asks: the borrower (or the person who typed the loan in for them), holding `loan.renew`, while things are
 *    out and the loan is NOT overdue (the due date itself is still in time).
 *  - How far: at most `loans.default_loan_days` past the current due date per request; never more than
 *    `loans.max_renewals` approved renewals; and the new due date never later than `loans.max_total_days` after the
 *    day of hand-out (or twice the shortest `max_loan_days` of the assets on the document, when that is less).
 *  - The first request of a loan is APPROVED at once. Every later one is PENDING until an approver of the loan's
 *    branch (`loan.approve`, LoanAccess::isApproverFor) decides; `loans.due_date` changes only on approval, and
 *    `original_due_date` and the earlier rows are never rewritten.
 *  - Deciding one's own renewal is not banned (CEO: the rule of approvals applies); it is recorded as
 *    LOAN_SELF_RENEW_APPROVE / LOAN_SELF_RENEW_REJECT. (Spec BR-L07 said "not the borrower"; flagged for confirmation.)
 *
 * Both actions run in one transaction on the locked loan row, so two requests (or two decisions) at once cannot both
 * pass: the second sees the first's row. Every request and decision writes an audit row.
 */
final class LoanRenewalService
{
    public function __construct(private readonly LoanAccess $access)
    {
    }

    /**
     * @param  string  $newDueDate  Y-m-d (validated by LoanRenewRequest)
     *
     * @throws LoanDomainException
     */
    public function request(User $actor, Loan $loan, string $newDueDate, ?string $reason): LoanDueChange
    {
        return DB::transaction(function () use ($actor, $loan, $newDueDate, $reason) {
            $locked = $this->lock($loan);

            if (! $actor->is_active || ! $this->access->isOwner($actor, $locked) || ! $actor->hasPermission('loan.renew')) {
                throw new LoanDomainException((string) __('loans.error.not_allowed'));
            }
            if (! $locked->isOut()) {
                throw new LoanDomainException((string) __('loans.error.renew_not_out'));
            }
            if ($locked->isOverdue()) {
                throw new LoanDomainException((string) __('loans.error.renew_overdue'));
            }
            if (LoanDueChange::where('loan_id', $locked->id)->where('status', 'PENDING')->exists()) {
                throw new LoanDomainException((string) __('loans.error.renew_pending_exists'));
            }

            // Every request counts here, rejected ones too: without this a borrower could ask again and again after
            // each rejection (rejections do not use up `max_renewals`) until `seq`, a TINYINT, overflowed at 256.
            $maxRequests = min(255, max(1, (int) config('loans.max_renewal_requests', 6)));
            if (LoanDueChange::where('loan_id', $locked->id)->count() >= $maxRequests) {
                throw new LoanDomainException((string) __('loans.error.renew_request_limit', ['max' => $maxRequests]));
            }

            $newDue = CarbonImmutable::parse($newDueDate)->startOfDay();
            $this->assertWithinLimits($locked, $newDue);

            $seq = (int) LoanDueChange::where('loan_id', $locked->id)->max('seq') + 1;
            // BR-L07: only the very first request of a loan passes by itself.
            $auto = $seq === 1;

            $change = new LoanDueChange(['reason' => $this->blankToNull($reason)]);
            $change->forceFill([
                'loan_id' => $locked->id,
                'seq' => $seq,
                'old_due_date' => $locked->due_date->toDateString(),
                'new_due_date' => $newDue->toDateString(),
                'requested_by' => $actor->id,
                'status' => $auto ? 'APPROVED' : 'PENDING',
                // Nobody approved an automatic one: approved_by stays empty, acted_at says when it took effect.
                'acted_at' => $auto ? now() : null,
                'created_at' => now(),
            ])->save();

            if ($auto) {
                $this->applyToLoan($locked, $change);
            }

            AuditLog::record(
                action: 'LOAN_RENEW_REQUEST',
                userId: $actor->id,
                username: $actor->username,
                entityType: Loan::class,
                entityId: $locked->id,
                oldValue: ['due_date' => $change->old_due_date->toDateString()],
                newValue: [
                    'doc_no' => $locked->doc_no,
                    'seq' => $seq,
                    'new_due_date' => $change->new_due_date->toDateString(),
                    'status' => $change->status,
                    'auto_approved' => $auto,
                ],
            );

            return $change;
        });
    }

    /**
     * An approver of the loan's branch decides a PENDING request (the 2nd and later ones).
     *
     * @param  'APPROVE'|'REJECT'  $decision
     *
     * @throws LoanDomainException
     */
    public function decide(User $approver, Loan $loan, int $seq, string $decision, ?string $note): LoanDueChange
    {
        return DB::transaction(function () use ($approver, $loan, $seq, $decision, $note) {
            $locked = $this->lock($loan);

            if (! $this->access->isApproverFor($approver, (int) $locked->lab_id)) {
                throw new LoanDomainException((string) __('loans.error.approver_not_eligible'));
            }

            $change = LoanDueChange::where('loan_id', $locked->id)->where('seq', $seq)->lockForUpdate()->first();
            // Decided already (two approvers at once: the first wins) or never existed.
            if ($change === null || $change->status !== 'PENDING') {
                throw new LoanDomainException((string) __('loans.error.invalid_transition'));
            }

            $note = $this->blankToNull($note);
            if ($decision === 'REJECT' && $note === null) {
                throw new LoanDomainException((string) __('loans.error.reason_required'));
            }

            if ($decision === 'APPROVE') {
                if (! $locked->isOut()) {
                    throw new LoanDomainException((string) __('loans.error.renew_not_out'));
                }
                $newDue = CarbonImmutable::parse($change->new_due_date->toDateString())->startOfDay();
                if ($newDue->lt(CarbonImmutable::today())) {
                    // The date asked for has itself gone by while the request waited: approving it would change nothing.
                    throw new LoanDomainException((string) __('loans.error.renew_new_due_passed'));
                }
                // The limits are checked again against today's state of the loan.
                $this->assertWithinLimits($locked, $newDue, false);
            }

            $change->forceFill([
                'status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                'approved_by' => $approver->id,
                'acted_at' => now(),
                'decision_note' => $note,
            ])->save();

            $oldDue = $locked->due_date->toDateString();
            if ($decision === 'APPROVE') {
                $this->applyToLoan($locked, $change);
            }

            $self = $locked->borrower_id === $approver->id;
            AuditLog::record(
                action: 'LOAN_'.($self ? 'SELF_' : '').'RENEW_'.$decision,
                userId: $approver->id,
                username: $approver->username,
                entityType: Loan::class,
                entityId: $locked->id,
                oldValue: ['due_date' => $oldDue],
                newValue: [
                    'doc_no' => $locked->doc_no,
                    'seq' => $change->seq,
                    'due_date' => $locked->due_date->toDateString(),
                    'approver_id' => $approver->id,
                    'self_approval' => $self,
                    'approver_is_requester' => $change->requested_by === $approver->id,
                ],
                message: $self ? 'borrower = approver' : null,
            );

            return $change;
        });
    }

    /**
     * The latest date this loan could be renewed to today, or null when it cannot be renewed at all (for the form).
     */
    public function latestPossibleDue(Loan $loan): ?CarbonImmutable
    {
        if (! $loan->isOut() || $loan->isOverdue() || $loan->renew_count >= (int) config('loans.max_renewals', 3)) {
            return null;
        }

        $current = CarbonImmutable::parse($loan->due_date->toDateString())->startOfDay();
        $latest = $current->addDays((int) config('loans.default_loan_days', 7));
        $cap = $this->totalCap($loan);
        if ($cap !== null && $cap->lt($latest)) {
            $latest = $cap;
        }

        return $latest->gt($current) ? $latest : null;
    }

    /** `loans.due_date` moves here and nowhere else after approval; `original_due_date` is never touched. */
    private function applyToLoan(Loan $loan, LoanDueChange $change): void
    {
        $loan->forceFill([
            'due_date' => $change->new_due_date->toDateString(),
            'renew_count' => $loan->renew_count + 1,
        ])->save();
    }

    /**
     * @param  bool  $checkStep  false when deciding: the "one step at a time" rule was checked when it was asked for,
     *                           against the due date of that day (which is the row's old_due_date)
     *
     * @throws LoanDomainException
     */
    private function assertWithinLimits(Loan $loan, CarbonImmutable $newDue, bool $checkStep = true): void
    {
        $max = (int) config('loans.max_renewals', 3);
        if ($loan->renew_count >= $max) {
            throw new LoanDomainException((string) __('loans.error.renew_limit', ['max' => $max]));
        }

        $current = CarbonImmutable::parse($loan->due_date->toDateString())->startOfDay();
        if ($newDue->lte($current)) {
            throw new LoanDomainException((string) __('loans.error.renew_not_later'));
        }

        $step = (int) config('loans.default_loan_days', 7);
        if ($checkStep && (int) $current->diffInDays($newDue) > $step) {
            throw new LoanDomainException((string) __('loans.error.renew_step_too_long', ['days' => $step]));
        }

        $cap = $this->totalCap($loan);
        if ($cap !== null && $newDue->gt($cap)) {
            throw new LoanDomainException((string) __('loans.error.renew_total_too_long', ['date' => $cap->format('d/m/Y')]));
        }
    }

    /**
     * BR-L07's ceiling: `max_total_days` after the day of hand-out, or twice the shortest `max_loan_days` among the
     * document's assets when that is less. Null only for a loan that was never handed out (nothing to renew then).
     */
    private function totalCap(Loan $loan): ?CarbonImmutable
    {
        if ($loan->handed_out_at === null) {
            return null;
        }

        $days = (int) config('loans.max_total_days', 60);
        $shortest = LoanAsset::query()
            ->whereIn('id', $loan->lines()->pluck('loan_asset_id'))
            ->whereNotNull('max_loan_days')
            ->min('max_loan_days');
        if ($shortest !== null) {
            $days = min($days, 2 * (int) $shortest);
        }

        return CarbonImmutable::parse($loan->handed_out_at->toDateString())->startOfDay()->addDays($days);
    }

    private function lock(Loan $loan): Loan
    {
        return Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
