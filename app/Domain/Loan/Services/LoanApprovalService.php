<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanAsset;
use App\Models\LoanLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 step 2: the two decisions on a SUBMITTED loan.
 *
 *  - ADVISOR step (a STUDENT borrower, BR-L03 / BR-L14): only the advisor named on the document, active, holding
 *    `loan.approve_advisor`, never the borrower; ADMIN cannot stand in. Works for the signed email link and for
 *    a logged-in advisor alike, because the checks live here, not in the controller.
 *  - MANAGER step: any holder of `loan.approve` of the loan's own branch (every scientist and warehouse manager;
 *    `LoanAccess::isApproverFor`, the branch is required of everyone, ADMIN-only accounts hold no `loan.approve`).
 *
 * approvals.md K (CEO, 2026-11-04): nobody is banned from approving their own request, so the control is the record:
 * every manager decision writes an audit row, action LOAN_APPROVE / LOAN_REJECT, or LOAN_SELF_APPROVE /
 * LOAN_SELF_REJECT when borrower = approver. The person who TYPED the request in for someone else (created_by,
 * FR-LN-14) still cannot approve it (spec BR-L04). The old "advisor must not also be the manager approver" default
 * was cancelled by the CEO for requisitions and is not enforced here; the audit row says when it happened.
 *
 * CEO decision (2026-11-05, `loans.student_needs_manager_step` = false): the advisor's approval of a STUDENT's loan
 * makes it APPROVED (ready for hand-out) at once, after the same availability checks as the manager's approval.
 *
 * Both decisions run in one transaction on a locked row with the status checked again, so two people pressing
 * the button together produce exactly one decision (the first wins). Availability is checked again at approval
 * (BR-L02, second of three points) with the asset rows locked.
 */
final class LoanApprovalService
{
    public function __construct(
        private readonly LoanState $state,
        private readonly LoanAccess $access,
    ) {
    }

    /**
     * @param  'APPROVE'|'REJECT'  $decision
     *
     * @throws LoanDomainException
     */
    public function advisorDecide(Loan $loan, User $advisor, string $decision, ?string $reason = null, ?string $ipAddress = null): Loan
    {
        return DB::transaction(function () use ($loan, $advisor, $decision, $reason, $ipAddress) {
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            // One generic message for every "not you": nothing about the account or the document is revealed.
            if (! $this->access->isAdvisorOf($advisor, $locked)) {
                throw new LoanDomainException((string) __('loans.error.advisor_not_eligible'));
            }
            if ($decision === 'REJECT' && trim((string) $reason) === '') {
                throw new LoanDomainException((string) __('loans.error.reason_required'));
            }

            $next = $this->state->apply($locked->status, $decision === 'APPROVE' ? 'advisorApprove' : 'advisorReject', $locked->borrower_status);

            if ($next === 'APPROVED') {
                // CEO decision (2026-11-05): the advisor's approval of a student's loan is the last one. Same checks as
                // the manager's approval (asset ACTIVE, quantity <= available under asset row locks, due date not past),
                // all lines as requested; any failure throws here, before anything is written, so nothing is recorded.
                // Keeps the invariant "an APPROVED loan has qty_approved on every line" for hand-out (step 3).
                $this->approveLines($locked, [], null, true);
                $locked->forceFill(['approved_at' => now()]);
            }
            $locked->forceFill([
                'status' => $next,
                // Signed only by an approval; a rejection is not a signature.
                'advisor_signed_at' => $decision === 'APPROVE' ? now() : null,
                'reject_reason' => $decision === 'REJECT' ? trim((string) $reason) : null,
            ])->save();

            $this->record($locked, 'ADVISOR', $advisor, $decision, $reason, $ipAddress);

            AuditLog::record(
                action: $decision === 'APPROVE' ? 'LOAN_ADVISOR_APPROVE' : 'LOAN_ADVISOR_REJECT',
                userId: $advisor->id,
                username: $advisor->username,
                entityType: Loan::class,
                entityId: $locked->id,
                newValue: ['doc_no' => $locked->doc_no, 'lab_id' => $locked->lab_id, 'borrower_id' => $locked->borrower_id, 'status' => $next],
            );

            return $locked;
        });
    }

    /**
     * @param  'APPROVE'|'REJECT'  $decision
     * @param  array<int|string, string|int|null>  $approvedQuantities  line_no => approved quantity (blank = as requested)
     *
     * @throws LoanDomainException
     */
    public function managerDecide(
        Loan $loan,
        User $approver,
        string $decision,
        ?string $reason = null,
        ?string $ipAddress = null,
        array $approvedQuantities = [],
    ): Loan {
        return DB::transaction(function () use ($loan, $approver, $decision, $reason, $ipAddress, $approvedQuantities) {
            $locked = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();

            if (! $this->access->isApproverFor($approver, (int) $locked->lab_id)) {
                throw new LoanDomainException((string) __('loans.error.approver_not_eligible'));
            }
            // FR-LN-14 / BR-L04: whoever typed it in for the borrower does not also approve it.
            if ($locked->isOnBehalf() && $locked->created_by === $approver->id) {
                throw new LoanDomainException((string) __('loans.error.creator_cannot_approve'));
            }
            // BR-02 / BR-L03, checked on the column itself and not only inferred from the status.
            if ($locked->isStudentBorrower() && $locked->advisor_signed_at === null) {
                throw new LoanDomainException((string) __('loans.error.advisor_first'));
            }
            if ($decision === 'REJECT' && trim((string) $reason) === '') {
                throw new LoanDomainException((string) __('loans.error.reason_required'));
            }

            // Status first: a late second decision must fail on the transition, not on a quantity check.
            $next = $this->state->apply($locked->status, $decision === 'APPROVE' ? 'managerApprove' : 'managerReject', $locked->borrower_status);

            if ($decision === 'APPROVE') {
                $this->approveLines($locked, $approvedQuantities, $reason);
            }

            $locked->forceFill([
                'status' => $next,
                'approved_by' => $decision === 'APPROVE' ? $approver->id : null,
                'approved_at' => $decision === 'APPROVE' ? now() : null,
                'reject_reason' => $decision === 'REJECT' ? trim((string) $reason) : null,
            ])->save();

            $this->record($locked, 'MANAGER', $approver, $decision, $reason, $ipAddress);
            $this->auditManagerDecision($locked, $approver, $decision);

            return $locked;
        });
    }

    /**
     * BR-L02 (second point) + FR-LN-13: sets `qty_approved` on every line, never above what was asked for and never
     * above what is available now (asset rows locked in id order so two approvals cannot deadlock), and needs a
     * reason when any line is cut. Writes nothing unless every line passes.
     *
     * @param  array<int|string, string|int|null>  $approvedQuantities
     */
    private function approveLines(Loan $loan, array $approvedQuantities, ?string $reason, bool $byAdvisor = false): void
    {
        // The advisor's wording differs ("tell the borrower"), the checks are the same.
        $sfx = $byAdvisor ? '_advisor' : '';

        if ($loan->due_date->lt(CarbonImmutable::today())) {
            throw new LoanDomainException((string) __('loans.error.due_passed'.$sfx));
        }

        $lines = $loan->lines()->get();
        $assets = LoanAsset::with('item')
            ->whereIn('id', $lines->pluck('loan_asset_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var list<array{0: LoanLine, 1: numeric-string}> $resolved */
        $resolved = [];
        $reduced = false;

        foreach ($lines as $line) {
            $asset = $assets->get($line->loan_asset_id);
            if ($asset === null || $asset->status !== 'ACTIVE' || (int) $asset->lab_id !== (int) $loan->lab_id) {
                throw new LoanDomainException((string) __('loans.error.asset_not_lendable'.$sfx));
            }

            $raw = $approvedQuantities[$line->line_no] ?? null;
            $text = $raw === null || trim((string) $raw) === '' ? null : trim((string) $raw);
            if ($text === null) {
                $approved = $line->qty;
            } else {
                if (preg_match('/^[0-9]{1,9}$/', $text) !== 1 || (int) $text < 1) {
                    throw new LoanDomainException((string) __('loans.error.qty_approved_invalid'));
                }
                $approved = bcadd((string) (int) $text, '0', 6);
            }

            if (bccomp($approved, $line->qty, 6) > 0) {
                throw new LoanDomainException((string) __('loans.error.approve_more_than_asked'));
            }
            if (bccomp($approved, $line->qty, 6) < 0) {
                $reduced = true;
            }
            if (bccomp($approved, $asset->available(), 6) > 0) {
                throw new LoanDomainException((string) __('loans.error.not_enough_available'.$sfx, ['item' => (string) $asset->item?->name_th]));
            }

            $resolved[] = [$line, $approved];
        }

        if ($reduced && trim((string) $reason) === '') {
            throw new LoanDomainException((string) __('loans.error.reduce_reason_required'));
        }

        foreach ($resolved as [$line, $approved]) {
            $line->forceFill(['qty_approved' => $approved])->save();
        }
    }

    private function record(Loan $loan, string $step, User $actor, string $decision, ?string $reason, ?string $ipAddress): void
    {
        LoanApproval::create([
            'loan_id' => $loan->id,
            'step' => $step,
            'actor_id' => $actor->id,
            'decision' => $decision,
            'reason' => $reason === null || trim($reason) === '' ? null : trim($reason),
            'acted_at' => now(),
            'ip_address' => $ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) !== false ? inet_pton($ipAddress) : null,
        ]);
    }

    /**
     * @param  'APPROVE'|'REJECT'  $decision
     */
    private function auditManagerDecision(Loan $loan, User $actor, string $decision): void
    {
        $self = $loan->borrower_id === $actor->id;
        $verb = $decision === 'APPROVE' ? 'APPROVE' : 'REJECT';

        AuditLog::record(
            action: 'LOAN_'.($self ? 'SELF_' : '').$verb,
            userId: $actor->id,
            username: $actor->username,
            entityType: Loan::class,
            entityId: $loan->id,
            newValue: [
                'doc_no' => $loan->doc_no,
                'lab_id' => $loan->lab_id,
                'borrower_id' => $loan->borrower_id,
                'created_by' => $loan->created_by,
                'approver_id' => $actor->id,
                'self_approval' => $self,
                'advisor_also_approver' => $loan->advisor_id !== null && $loan->advisor_id === $actor->id,
                'status' => $loan->status,
            ],
            message: $self ? 'borrower = approver' : null,
        );
    }
}
