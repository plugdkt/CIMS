<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Shared\UnitConverter;
use App\Mail\AdvisorApprovalMail;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * FR-RQ-01..05: the requisition form's line items and DRAFT→SUBMITTED transition.
 * Approval decisions (T-032), issuing (T-035), and everything after SUBMITTED belong to
 * later services — this one only owns getting a DRAFT requisition ready and submitted.
 */
final class RequisitionService
{
    public function __construct(
        private readonly UnitConverter $converter,
        private readonly RequisitionState $state,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param  numeric-string  $qtyRequested
     * @param  array<string, mixed>  $extra  reference_doc/remark
     */
    public function addLine(
        Requisition $requisition,
        Item $item,
        Unit $unit,
        string $qtyRequested,
        array $extra = [],
    ): RequisitionItem {
        if ($requisition->status !== 'DRAFT') {
            throw new InvalidRequisitionTransitionException('เพิ่มรายการได้เฉพาะใบเบิกที่ยังเป็นสถานะร่าง (DRAFT) เท่านั้น');
        }

        $nextLineNo = 1 + (int) $requisition->items()->max('line_no');
        $qtyRequestedBase = $this->converter->toItemBase($item, $unit, $qtyRequested);

        return $requisition->items()->create(array_merge([
            'line_no' => $nextLineNo,
            'item_id' => $item->id,
            'qty_requested' => $qtyRequested,
            'unit_id' => $unit->id,
            'qty_requested_base' => $qtyRequestedBase,
        ], $extra));
    }

    /**
     * BR-01: DRAFT → SUBMITTED. Requires at least one line item.
     *
     * FR-RQ-07: a STUDENT's requisition also needs advisor sign-off before a scientist can
     * act on it (BR-02) — email the advisor a 72-hour signed link the moment it's
     * submitted, so they can approve without needing to log in.
     */
    public function submit(Requisition $requisition): Requisition
    {
        if ($requisition->items()->count() === 0) {
            throw new InvalidRequisitionTransitionException('ต้องมีอย่างน้อย 1 รายการก่อนส่งใบเบิก');
        }

        $requisition->status = $this->state->apply($requisition->status, 'submit');
        $requisition->submitted_at = now();
        $requisition->save();

        $advisor = $requisition->advisor;
        if ($requisition->requester_status === 'STUDENT' && $advisor !== null) {
            $this->mailAdvisor($requisition, $advisor);
            // FR-NT-03's "Email" channel for this step is already the richer signed-URL
            // email above (T-033) — sending a second, generic notification email would
            // just duplicate it. Only the in-app half is new here.
            $this->notifications->notifyInApp(
                $advisor,
                'requisition.pending_advisor',
                __('notifications.pending_advisor_title'),
                __('notifications.pending_advisor_body', [
                    'doc_no' => $requisition->doc_no,
                    'requester' => (string) $requisition->requester?->full_name,
                ]),
                route('requisitions.show', $requisition),
            );
        } elseif ($requisition->requester_status !== 'STUDENT') {
            $this->notifyScientistsPending($requisition);
        }

        return $requisition;
    }

    private function mailAdvisor(Requisition $requisition, User $advisor): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'requisitions.approve.signed',
            now()->addHours(72),
            ['requisition' => $requisition->ulid],
        );

        Mail::to($advisor->email)->send(new AdvisorApprovalMail($requisition, $signedUrl));
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

    /** BR-01: DRAFT | SUBMITTED → CANCELLED. */
    public function cancel(Requisition $requisition): Requisition
    {
        $requisition->status = $this->state->apply($requisition->status, 'cancel');
        $requisition->save();

        return $requisition;
    }
}
