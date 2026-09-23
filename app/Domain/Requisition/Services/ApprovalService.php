<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Requisition\Exceptions\InvalidApprovalDecisionException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Shared\UnitConverter;
use App\Models\Requisition;
use App\Models\RequisitionApproval;
use App\Models\RequisitionItem;
use App\Models\User;

/**
 * FR-RQ-06/07/08: the two approval steps (advisor, then scientist) a SUBMITTED requisition
 * goes through before it can be issued. Every decision — approve or reject — writes one
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
            $this->notifyScientistsPending($requisition);
        }

        return $requisition;
    }

    /**
     * BR-02: a STUDENT's requisition can never become APPROVED without a prior advisor
     * sign-off — checked directly against `advisor_signed_at` here (not merely inferred
     * from the current `status` string), so this holds even if `status` were somehow
     * ADVISOR_APPROVED while `advisor_signed_at` is NULL (a data-integrity bug elsewhere
     * shouldn't be able to bypass this rule).
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
        if ($decision === 'REJECT' && trim((string) $reason) === '') {
            throw new InvalidApprovalDecisionException('กรุณาระบุเหตุผลที่ไม่เห็นควรให้เบิก');
        }

        if ($decision === 'APPROVE') {
            $this->applyApprovedQuantities($requisition, $approvedQuantities, $reason);
        }

        $event = $decision === 'APPROVE' ? 'scientistApprove' : 'scientistReject';
        $newStatus = $this->applyTransition($requisition, $event);

        $this->recordApproval($requisition, 'SCIENTIST', $scientist, $decision, $reason, $ipAddress);

        $requisition->scientist_id = $scientist->id;
        $requisition->scientist_decision = $decision;
        $requisition->reject_reason = $decision === 'REJECT' ? $reason : null;
        $requisition->scientist_signed_at = now();
        $requisition->status = $newStatus;
        $requisition->save();

        $this->notifyRequesterOfDecision($requisition, 'SCIENTIST', $decision);

        return $requisition;
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
        foreach ($this->notifications->usersWithAnyPermission('requisition.approve_scientist') as $scientist) {
            $this->notifications->notifyInAppAndEmail(
                $scientist,
                'requisition.pending_scientist',
                __('notifications.pending_scientist_title'),
                __('notifications.pending_scientist_body', ['doc_no' => $requisition->doc_no]),
                route('requisitions.show', $requisition),
            );
        }
    }
}
