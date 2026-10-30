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
 * `$requesterStatus` is accepted but currently ignored: BR-02 (a STUDENT's requisition must
 * pass through ADVISOR_APPROVED first) was cancelled, so a SUBMITTED requisition may go
 * straight to a scientist decision (`scientistApprove` / `scientistReject`) for every
 * requester type, as well as via ADVISOR_APPROVED. See CLAUDE.md (BR-02 cancellation).
 * This class only knows the abstract state diagram.
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
