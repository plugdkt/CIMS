<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;

/**
 * BR-01: the requisition `status` state machine, expressed as a pure lookup — no side
 * effects, no persistence. Callers (`ApprovalService` T-032, the issue flow T-035, …) ask
 * `apply()` for the next status and persist it themselves; `Requisition::status` is the
 * only place the result is ever stored.
 *
 * `$requesterStatus` disambiguates the one context-dependent edge in the spec's diagram: a
 * SUBMITTED requisition only goes straight to a scientist decision (`scientistApprove` /
 * `scientistReject`) when the requester is NOT a STUDENT — spec's "SUBMITTED(non-student)"
 * branch, grouped with ADVISOR_APPROVED in "ADVISOR_APPROVED | SUBMITTED(non-student) ──
 * scientist APPROVE/REJECT──►". A STUDENT's requisition must pass through ADVISOR_APPROVED
 * first. BR-02's stronger check (actually verifying `advisor_signed_at IS NULL` on the real
 * model, not just the requester's role) belongs to `ApprovalService` (T-032) — this class
 * only knows the abstract state diagram.
 */
final class RequisitionState
{
    /** @var array<string, array<string, string>> */
    private const TRANSITIONS = [
        'DRAFT' => [
            'submit' => 'SUBMITTED',
            'cancel' => 'CANCELLED',
        ],
        'SUBMITTED' => [
            'advisorApprove' => 'ADVISOR_APPROVED',
            'advisorReject' => 'REJECTED',
            'cancel' => 'CANCELLED',
        ],
        'ADVISOR_APPROVED' => [
            'scientistApprove' => 'APPROVED',
            'scientistReject' => 'REJECTED',
        ],
        'APPROVED' => [
            'issuePartial' => 'PARTIALLY_ISSUED',
            'issueFull' => 'ISSUED',
        ],
        'PARTIALLY_ISSUED' => [
            'issuePartial' => 'PARTIALLY_ISSUED',
            'issueFull' => 'ISSUED',
        ],
        'REJECTED' => [],
        'ISSUED' => [],
        'CANCELLED' => [],
    ];

    private const SUBMITTED_SCIENTIST_EVENTS = ['scientistApprove', 'scientistReject'];

    public function can(string $from, string $event, ?string $requesterStatus = null): bool
    {
        if ($from === 'SUBMITTED' && in_array($event, self::SUBMITTED_SCIENTIST_EVENTS, true)) {
            return true;
        }

        return isset(self::TRANSITIONS[$from][$event]);
    }

    /**
     * @return 'DRAFT'|'SUBMITTED'|'ADVISOR_APPROVED'|'APPROVED'|'REJECTED'|'PARTIALLY_ISSUED'|'ISSUED'|'CANCELLED'
     */
    public function apply(string $from, string $event, ?string $requesterStatus = null): string
    {
        if ($from === 'SUBMITTED' && in_array($event, self::SUBMITTED_SCIENTIST_EVENTS, true)) {
            return $event === 'scientistApprove' ? 'APPROVED' : 'REJECTED';
        }

        return self::TRANSITIONS[$from][$event]
            ?? throw new InvalidRequisitionTransitionException(
                "ไม่สามารถเปลี่ยนสถานะจาก {$from} ด้วยเหตุการณ์ {$event} ได้"
            );
    }
}
