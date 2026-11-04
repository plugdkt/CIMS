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
 * `$requesterStatus` disambiguates the context-dependent edges. A SUBMITTED requisition only
 * goes straight to a scientist decision (`scientistApprove` / `scientistReject`) when the
 * requester is NOT a STUDENT (a known, non-student status). A STUDENT's requisition needs the
 * advisor (BR-02, restored 2026-10-30, ITM-2610-046) and, since CEO decision 2026-11-04 (approvals.md
 * K, option "ก"), the advisor's approval makes it APPROVED at once: the scientist/manager step is
 * skipped for students only (ADVISOR_APPROVED remains for older rows and for a non-student who has an
 * advisor). A null `$requesterStatus` argument is treated like a STUDENT by this class.
 * BR-02's stronger check (actually verifying `advisor_signed_at` is set on the real model,
 * not just the requester's type) belongs to `ApprovalService` (T-032) — this class only
 * knows the abstract state diagram.
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
            return $requesterStatus !== null && $requesterStatus !== 'STUDENT';
        }

        return isset(self::TRANSITIONS[$from][$event]);
    }

    /**
     * @return 'DRAFT'|'SUBMITTED'|'ADVISOR_APPROVED'|'APPROVED'|'REJECTED'|'PARTIALLY_ISSUED'|'ISSUED'|'CANCELLED'
     */
    public function apply(string $from, string $event, ?string $requesterStatus = null): string
    {
        // approvals.md K (CEO, 2026-11-04, option "ก"): the advisor is the approver of a STUDENT's requisition —
        // once the advisor approves, it is APPROVED and ready to issue; the scientist/warehouse-manager approval
        // step is skipped for students only. A non-student that happens to have an advisor keeps the old
        // two-step path (SUBMITTED -> ADVISOR_APPROVED -> scientist decision).
        if ($from === 'SUBMITTED' && $event === 'advisorApprove' && $requesterStatus === 'STUDENT') {
            return 'APPROVED';
        }

        if ($from === 'SUBMITTED' && in_array($event, self::SUBMITTED_SCIENTIST_EVENTS, true)) {
            if ($requesterStatus === null || $requesterStatus === 'STUDENT') {
                throw new InvalidRequisitionTransitionException(
                    "ไม่สามารถเปลี่ยนสถานะจาก {$from} ด้วยเหตุการณ์ {$event} ได้"
                );
            }

            return $event === 'scientistApprove' ? 'APPROVED' : 'REJECTED';
        }

        return self::TRANSITIONS[$from][$event]
            ?? throw new InvalidRequisitionTransitionException(
                "ไม่สามารถเปลี่ยนสถานะจาก {$from} ด้วยเหตุการณ์ {$event} ได้"
            );
    }
}
