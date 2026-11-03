<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Shared\ItemType;
use App\Domain\Shared\UnitConverter;
use App\Mail\AdvisorApprovalMail;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        // Two simultaneous clicks must not both pass the "one kind / still a draft" checks: lock the row first.
        return DB::transaction(function () use ($requisition, $item, $unit, $qtyRequested, $extra) {
            $this->lockAndRefresh($requisition);

            return $this->addLineLocked($requisition, $item, $unit, $qtyRequested, $extra);
        });
    }

    /** Takes a row lock on the requisition (inside the caller's transaction) and reloads it so every check sees the committed state. */
    private function lockAndRefresh(Requisition $requisition): void
    {
        Requisition::whereKey($requisition->id)->lockForUpdate()->first();
        $requisition->refresh();
    }

    /**
     * @param  numeric-string  $qtyRequested
     * @param  array<string, mixed>  $extra
     */
    private function addLineLocked(
        Requisition $requisition,
        Item $item,
        Unit $unit,
        string $qtyRequested,
        array $extra,
    ): RequisitionItem {
        if ($requisition->status !== 'DRAFT') {
            throw new InvalidRequisitionTransitionException('เพิ่มรายการได้เฉพาะใบเบิกที่ยังเป็นสถานะร่าง (DRAFT) เท่านั้น');
        }

        // ITM-2610-052 phase 2: one requisition = one kind of stock (chemical or consumable).
        $isChemical = ItemType::isChemical(ItemType::forItem($item));
        $declared = $requisition->request_type;
        if ($declared !== [] && ! in_array(ItemType::requestTypeValue(ItemType::forItem($item)), $declared, true)) {
            throw new InvalidRequisitionTransitionException((string) __('requisitions.validation.item_wrong_type'));
        }
        // A legacy draft that was ticked as both kinds must still not receive a second kind once it has lines.
        $hasOtherKind = $requisition->items()
            ->whereHas('item.category', fn ($c) => $c->where('is_chemical', ! $isChemical))
            ->exists();
        if ($hasOtherKind) {
            throw new InvalidRequisitionTransitionException((string) __('requisitions.validation.mixed_item_types'));
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
     * FR-RQ-07: for a STUDENT's requisition the advisor is emailed a 72-hour signed link the
     * moment it's submitted (plus an in-app notification), so they can approve without
     * logging in. BR-02 (restored by CEO decision 2026-10-30, ITM-2610-046): that sign-off is
     * a gate for a STUDENT's requisition — the scientist cannot decide it until the advisor
     * has approved. So a STUDENT with no advisor could never get it decided: refuse to
     * submit such a requisition rather than let it sit SUBMITTED forever. (If the requester
     * has since chosen an advisor on their profile, the draft picks it up here.)
     */
    public function submit(Requisition $requisition): Requisition
    {
        // The checks and the status change happen under a row lock so a line cannot slip in (or the
        // requisition be submitted twice) between them; mails/notifications go out after the commit.
        DB::transaction(function () use ($requisition) {
            $this->lockAndRefresh($requisition);

            if ($requisition->items()->count() === 0) {
                throw new InvalidRequisitionTransitionException('ต้องมีอย่างน้อย 1 รายการก่อนส่งใบเบิก');
            }

            $this->assertSingleKind($requisition);

            if ($requisition->requester_status === 'STUDENT' && $requisition->advisor_id === null) {
                $profileAdvisorId = $requisition->requester?->advisor_id;
                if ($profileAdvisorId === null) {
                    throw new InvalidRequisitionTransitionException((string) __('requisitions.advisor_missing'));
                }
                $requisition->advisor_id = $profileAdvisorId;
            }

            $requisition->status = $this->state->apply($requisition->status, 'submit');
            $requisition->submitted_at = now();
            $requisition->save();
        });

        $advisor = $requisition->advisor;
        if ($requisition->requester_status === 'STUDENT' && $advisor !== null) {
            $this->mailAdvisor($requisition, $advisor);
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
            // A STUDENT's requisition is not actionable by the warehouse until the advisor
            // approves (ApprovalService::advisorDecide() notifies them then).
            $this->notifyScientistsPending($requisition);
        }

        return $requisition;
    }

    /**
     * Phase 2 rule, checked at submit as well as at add-line (drafts created before it can still hold
     * mixed lines): every line is the same kind, and that kind is the one the requisition declares.
     */
    private function assertSingleKind(Requisition $requisition): void
    {
        $hasChemical = $requisition->items()->whereHas('item.category', fn ($c) => $c->where('is_chemical', true))->exists();
        $hasOther = $requisition->items()->whereHas('item.category', fn ($c) => $c->where('is_chemical', false))->exists();

        if ($hasChemical && $hasOther) {
            throw new InvalidRequisitionTransitionException((string) __('requisitions.validation.mixed_item_types'));
        }

        // The lines are one kind. The requisition's own `request_type` is only a record of what was ticked
        // (old drafts could tick the wrong box), so align it to the real lines instead of blocking the
        // submit; it is saved together with the status change in submit().
        $kind = ItemType::requestTypeValue($hasChemical ? ItemType::CHEMICAL : ItemType::CONSUMABLE);
        if ($requisition->request_type !== [$kind]) {
            $requisition->request_type = [$kind];
        }
    }

    private function mailAdvisor(Requisition $requisition, User $advisor): void
    {
        if (empty($advisor->email) || filter_var($advisor->email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $signedUrl = URL::temporarySignedRoute(
            'requisitions.approve.signed',
            now()->addHours(72),
            ['requisition' => $requisition->ulid],
        );

        try {
            Mail::to($advisor->email)->send(new AdvisorApprovalMail($requisition, $signedUrl));
        } catch (\Throwable $e) {
            Log::warning("Failed to send advisor approval email for {$requisition->doc_no}: {$e->getMessage()}");
        }
    }

    /** FR-NT-03: a requisition just became actionable by a scientist — notify every SCIENTIST. */
    private function notifyScientistsPending(Requisition $requisition): void
    {
        $this->notifications->notifyApprovalPending($requisition);
    }

    /** BR-01: DRAFT | SUBMITTED → CANCELLED. */
    public function cancel(Requisition $requisition): Requisition
    {
        $requisition->status = $this->state->apply($requisition->status, 'cancel');
        $requisition->save();

        return $requisition;
    }
}
