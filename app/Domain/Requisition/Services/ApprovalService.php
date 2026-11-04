<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Requisition\Exceptions\InvalidApprovalDecisionException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Shared\UnitConverter;
use App\Models\AuditLog;
use App\Models\Requisition;
use App\Models\RequisitionApproval;
use App\Models\RequisitionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * FR-RQ-06/07/08: the advisor and scientist (any scientist of the branch, or a warehouse manager) decisions
 * on a SUBMITTED requisition. BR-02 (restored 2026-10-30, ITM-2610-046): a STUDENT's requisition needs the
 * advisor's sign-off; since CEO decision 2026-11-04 (approvals.md K) that sign-off alone makes it APPROVED
 * (ready to issue) and the scientist step is skipped for students. Any other requester's SUBMITTED
 * requisition goes straight to a scientist decision. Every decision — approve or reject — writes one
 * `requisition_approvals` row (the audit trail FR-RQ-07/08 both rely on) and advances
 * `Requisition::status` through `RequisitionState` (BR-01).
 */
final class ApprovalService
{
    private const SCALE = 6;

    public function __construct(
        private readonly RequisitionState $state,
        private readonly NotificationService $notifications,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * Only the requisition's own `advisor_id` may act here — enforced here (a business
     * rule about *which* advisor, not just "has the advisor permission") rather than left
     * to the Policy layer, the same way BR-06's distinct-approver rule lives inside
     * `LedgerService::adjust()` rather than only in a Policy.
     *
     * @param  'APPROVE'|'REJECT'  $decision
     */
    public function advisorDecide(
        Requisition $requisition,
        User $advisor,
        string $decision,
        ?string $reason = null,
        ?string $ipAddress = null,
    ): Requisition {
        if ($requisition->advisor_id !== $advisor->id) {
            throw new InvalidApprovalDecisionException('อาจารย์ที่ปรึกษาอนุมัติได้เฉพาะใบเบิกของนิสิตในความดูแลของตนเองเท่านั้น');
        }

        // BR-02 hardening (CEO 2026-11-02): being named as the advisor is not enough. The advisor must
        // still be active, still hold the advisor permission, and must not be the requester — whichever
        // channel (signed email link or logged in) the decision comes through. One generic message, so
        // nothing about the account or the requisition is revealed.
        if (
            ! $advisor->is_active
            || $advisor->id === $requisition->requester_id
            || ! $advisor->hasPermission('requisition.approve_advisor')
        ) {
            throw new InvalidApprovalDecisionException((string) __('requisitions.advisor_not_eligible'));
        }

        if ($decision === 'REJECT' && trim((string) $reason) === '') {
            throw new InvalidApprovalDecisionException('กรุณาระบุเหตุผลที่ไม่อนุมัติ');
        }

        $event = $decision === 'APPROVE' ? 'advisorApprove' : 'advisorReject';
        $newStatus = $this->applyTransition($requisition, $event);

        $this->recordApproval($requisition, 'ADVISOR', $advisor, $decision, $reason, $ipAddress);

        $requisition->advisor_signed_at = now();
        if ($decision === 'APPROVE') {
            $requisition->advisor_signature_hash = $this->signatureHash($requisition, $advisor, $decision);
        }
        $requisition->status = $newStatus;
        $requisition->save();

        $this->notifyRequesterOfDecision($requisition, 'ADVISOR', $decision);
        if ($decision === 'APPROVE') {
            // A student's requisition is APPROVED by the advisor alone (approvals.md K): tell the dispensers.
            // Anyone else still goes to the scientist/manager approval step.
            if ($requisition->status === 'APPROVED') {
                $this->notifications->notifyReadyToIssue($requisition);
            } else {
                $this->notifyScientistsPending($requisition);
            }
        }

        return $requisition;
    }

    /**
     * Scientist (warehouse manager) decision on a requisition.
     * BR-02: a STUDENT's requisition can never be decided by the scientist without a prior
     * advisor sign-off — checked directly against `advisor_signed_at` (not merely inferred
     * from the current `status`), so this holds even if `status` were somehow
     * ADVISOR_APPROVED while `advisor_signed_at` is NULL. A non-student's SUBMITTED
     * requisition may be decided at once (the transition is validated by `RequisitionState`).
     * A REJECT needs a reason.
     *
     * @param  'APPROVE'|'REJECT'  $decision
     * @param  array<int, string>  $approvedQuantities  requisition_item id => approved qty, in that line's own unit
     */
    public function scientistDecide(
        Requisition $requisition,
        User $scientist,
        string $decision,
        ?string $reason = null,
        ?string $ipAddress = null,
        array $approvedQuantities = [],
    ): Requisition {
        // approvals.md K (2026-11-04): any scientist of the branch may decide, so two of them can press the
        // button on the same requisition at once. The row is locked and its status read again inside one
        // transaction; whoever gets the lock second sees APPROVED/REJECTED, and RequisitionState refuses the
        // transition (first one wins, no second `requisition_approvals` row, no second set of line quantities).
        $decided = DB::transaction(function () use ($requisition, $scientist, $decision, $reason, $ipAddress, $approvedQuantities): Requisition {
            /** @var Requisition $locked */
            $locked = Requisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if ($locked->requester_status === 'STUDENT' && $locked->advisor_signed_at === null) {
                throw new InvalidApprovalDecisionException(
                    'ไม่สามารถพิจารณาได้ (BR-02): ใบเบิกของนิสิตต้องผ่านการอนุมัติจากอาจารย์ที่ปรึกษาก่อน'
                );
            }

            if ($decision === 'REJECT' && trim((string) $reason) === '') {
                throw new InvalidApprovalDecisionException('กรุณาระบุเหตุผลที่ไม่เห็นควรให้เบิก');
            }

            // Status first: a second, late decision must fail on the transition, not on quantity checks.
            $event = $decision === 'APPROVE' ? 'scientistApprove' : 'scientistReject';
            $newStatus = $this->applyTransition($locked, $event);

            if ($decision === 'APPROVE') {
                $this->applyApprovedQuantities($locked, $approvedQuantities, $reason);
            }

            $this->recordApproval($locked, 'SCIENTIST', $scientist, $decision, $reason, $ipAddress);

            $locked->scientist_id = $scientist->id;
            $locked->scientist_decision = $decision;
            $locked->reject_reason = $decision === 'REJECT' ? $reason : null;
            $locked->scientist_signed_at = now();
            $locked->status = $newStatus;
            $locked->save();

            $this->auditScientistDecision($locked, $scientist, $decision);

            return $locked;
        });

        // Mail/notifications only after the decision is committed: a mail problem must not undo it.
        $this->notifyRequesterOfDecision($decided, 'SCIENTIST', $decision);

        return $decided;
    }

    /**
     * approvals.md K: nobody is forbidden to approve their own requisition (CEO, 2026-11-04), so the control is the
     * record. Every scientist decision gets an audit row; when the approver is also the requester the row carries the
     * flag `self_approval` (action REQ_SELF_APPROVE / REQ_SELF_REJECT) so the auditor's report can find it. The
     * "issuer = requester / approver" flag is written at issue time (IssueService) and the report
     * (SelfApprovalReport) computes the same facts from the tables, so no new column is needed.
     *
     * @param  'APPROVE'|'REJECT'  $decision
     */
    private function auditScientistDecision(Requisition $requisition, User $actor, string $decision): void
    {
        $self = $requisition->requester_id === $actor->id;
        $action = ($self ? 'REQ_SELF_' : 'REQ_').($decision === 'APPROVE' ? 'APPROVE' : 'REJECT');

        AuditLog::record(
            action: $action,
            userId: $actor->id,
            username: $actor->username,
            entityType: 'requisition',
            entityId: $requisition->id,
            newValue: [
                'doc_no' => $requisition->doc_no,
                'lab_id' => $requisition->lab_id,
                'requester_id' => $requisition->requester_id,
                'approver_id' => $actor->id,
                'self_approval' => $self,
            ],
            message: $self ? 'requester = approver' : null,
        );
    }

    /**
     * User-requested 2026-09-23: a warehouse manager may approve less than what was
     * requested, per line — e.g. requested 100, approves 50 as the appropriate amount.
     * Reducing any line requires a reason, mirroring BR-04's remark requirement for the
     * opposite case (issuing *more* than requested) — this changes the ceiling every later
     * issuance check compares against ({@see RequisitionItem::approvedCeilingBase()}).
     *
     * Validates every line before writing any of them, so a rejected request (over the
     * requested amount, or reduced with no reason) never leaves a partial write behind.
     *
     * @param  array<int, string>  $approvedQuantities
     */
    private function applyApprovedQuantities(Requisition $requisition, array $approvedQuantities, ?string $reason): void
    {
        $requisition->load(['items.item', 'items.unit']);

        /** @var list<array{0: RequisitionItem, 1: string, 2: string}> $resolved */
        $resolved = [];
        $reduced = false;

        foreach ($requisition->items as $line) {
            $rawApproved = $approvedQuantities[$line->id] ?? null;
            $rawApproved = ($rawApproved === null || trim($rawApproved) === '')
                ? (string) $line->qty_requested
                : $rawApproved;

            $item = $line->item()->firstOrFail();
            $unit = $line->unit()->firstOrFail();
            /** @var numeric-string $rawApproved */
            $approvedBase = $this->converter->toItemBase($item, $unit, $rawApproved);

            if (bccomp($approvedBase, $line->qty_requested_base, self::SCALE) > 0) {
                throw new InvalidApprovalDecisionException('อนุมัติจำนวนมากกว่าที่ขอเบิกไม่ได้');
            }
            if (bccomp($approvedBase, $line->qty_requested_base, self::SCALE) < 0) {
                $reduced = true;
            }

            $resolved[] = [$line, $rawApproved, $approvedBase];
        }

        if ($reduced && trim((string) $reason) === '') {
            throw new InvalidApprovalDecisionException('กรุณาระบุเหตุผลเมื่ออนุมัติจำนวนน้อยกว่าที่ขอเบิก (BR-04)');
        }

        foreach ($resolved as [$line, $rawApproved, $approvedBase]) {
            /**
             * @var numeric-string $rawApproved
             * @var numeric-string $approvedBase
             */
            $line->qty_approved = $rawApproved;
            $line->qty_approved_base = $approvedBase;
            $line->save();
        }
    }

    /**
     * @return 'DRAFT'|'SUBMITTED'|'ADVISOR_APPROVED'|'APPROVED'|'REJECTED'|'PARTIALLY_ISSUED'|'ISSUED'|'CANCELLED'
     */
    private function applyTransition(Requisition $requisition, string $event): string
    {
        try {
            return $this->state->apply($requisition->status, $event, $requisition->requester_status);
        } catch (InvalidRequisitionTransitionException $e) {
            throw new InvalidApprovalDecisionException($e->getMessage());
        }
    }

    private function recordApproval(
        Requisition $requisition,
        string $step,
        User $actor,
        string $decision,
        ?string $reason,
        ?string $ipAddress,
    ): void {
        RequisitionApproval::create([
            'requisition_id' => $requisition->id,
            'step' => $step,
            'actor_id' => $actor->id,
            'decision' => $decision,
            'reason' => $reason,
            'acted_at' => now(),
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * No spec-defined formula for this field (unlike BR-08's explicit ledger hash chain) —
     * a lightweight non-repudiation marker for a decision made without a drawn signature
     * (that's T-036's e-signature canvas, for the *receiver* at issue time, not the
     * advisor). Scoped to this one decision, not a chain.
     */
    private function signatureHash(Requisition $requisition, User $advisor, string $decision): string
    {
        return hash('sha256', implode('|', [
            $requisition->id,
            $advisor->id,
            $decision,
            now()->toISOString(),
        ]));
    }

    /**
     * FR-NT-04: tell the requester the result of either approval step, approve or reject.
     *
     * @param  'ADVISOR'|'SCIENTIST'  $step
     * @param  'APPROVE'|'REJECT'  $decision
     */
    private function notifyRequesterOfDecision(Requisition $requisition, string $step, string $decision): void
    {
        $requester = $requisition->requester()->firstOrFail();
        $titleKey = $decision === 'APPROVE' ? 'notifications.decision_approved_title' : 'notifications.decision_rejected_title';
        $bodyKey = $step === 'ADVISOR' ? 'notifications.decision_body_advisor' : 'notifications.decision_body_scientist';

        $this->notifications->notifyInAppAndEmail(
            $requester,
            'requisition.decision',
            __($titleKey, ['doc_no' => $requisition->doc_no]),
            __($bodyKey, ['doc_no' => $requisition->doc_no]),
            route('requisitions.show', $requisition),
        );
    }

    /** FR-NT-03: a requisition just became actionable by a scientist — notify every SCIENTIST. */
    private function notifyScientistsPending(Requisition $requisition): void
    {
        $this->notifications->notifyApprovalPending($requisition);
    }
}
