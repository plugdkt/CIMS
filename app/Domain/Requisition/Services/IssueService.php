<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Shared\UnitConverter;
use App\Models\Container;
use App\Models\IssueTransaction;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;

/**
 * FR-RQ-09/10: issues stock against one requisition line from one container at a time —
 * call it once per container when a line is split across several (FR-RQ-10, "ขอ 800 mL
 * จ่ายจาก 2 ขวด"). `LedgerService::issue()` remains the only writer of `stock_ledger`; this
 * class owns the requisition-side bookkeeping (`issue_transactions`, `requisition_items.
 * qty_issued_base`, BR-04's tolerance check, and advancing `Requisition::status`).
 */
final class IssueService
{
    private const SCALE = 6;
    private const OVERAGE_APPROVAL_THRESHOLD = '0.10';

    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly RequisitionState $state,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * FR-RQ-11: `$signatureHash` is required — every issue needs the receiver's identity
     * confirmed, either a drawn signature (`$signatureImagePath` also set) or an OTP
     * (`$signatureImagePath` stays null; the hash alone marks that channel). Resolving
     * "which of the two" happens in the controller, via `SignatureImageService` or
     * `ReceiverOtpService` — this method just records whatever it's given.
     *
     * @param  numeric-string  $qtyIssued  in $unit, not yet converted to the item's base unit
     */
    public function issue(
        RequisitionItem $line,
        Container $container,
        string $qtyIssued,
        Unit $unit,
        User $issuer,
        User $receiver,
        string $signatureHash,
        ?string $signatureImagePath = null,
        ?string $remark = null,
        ?int $overageApprovedBy = null,
    ): IssueTransaction {
        $requisition = $line->requisition()->firstOrFail();

        if (! in_array($requisition->status, ['APPROVED', 'PARTIALLY_ISSUED'], true)) {
            throw new InvalidRequisitionTransitionException('จ่ายของได้เฉพาะใบเบิกที่อนุมัติแล้วเท่านั้น');
        }
        if ($line->item_id !== $container->item_id) {
            throw new InvalidRequisitionTransitionException('ภาชนะนี้ไม่ใช่สารตามรายการที่เลือก');
        }

        $qtyIssuedBase = $this->converter->toItemBase($line->item()->firstOrFail(), $unit, $qtyIssued);
        $newCumulative = bcadd($line->qty_issued_base, $qtyIssuedBase, self::SCALE);
        $this->assertWithinTolerance($line, $newCumulative, $remark, $overageApprovedBy);

        $this->ledgerService->issue($container->id, $qtyIssuedBase, new LedgerEntryData(
            displayUnitId: $unit->id,
            createdBy: $issuer->id,
            refType: 'REQUISITION',
            refId: $requisition->id,
            refDocNo: $requisition->doc_no,
            issuerId: $issuer->id,
            receiverId: $receiver->id,
            signatureHash: $signatureHash,
            remark: $remark,
        ));

        $issueTransaction = IssueTransaction::create([
            'requisition_item_id' => $line->id,
            'container_id' => $container->id,
            'qty_issued_base' => $qtyIssuedBase,
            'issued_at' => now(),
            'issuer_id' => $issuer->id,
            'receiver_id' => $receiver->id,
            'signature_hash' => $signatureHash,
            'signature_image_path' => $signatureImagePath,
            'remark' => $remark,
        ]);

        $line->qty_issued_base = $newCumulative;
        if ($overageApprovedBy !== null) {
            $line->overage_approved_by = $overageApprovedBy;
        }
        $line->save();

        $this->advanceRequisitionStatus($requisition);

        return $issueTransaction;
    }

    /**
     * BR-04: up to 10% over `qty_requested_base` just needs a remark; past 10% the
     * approver must actually hold `requisition.issue_override` (LAB_MANAGER per
     * PermissionSeeder) — mirrors BR-06's distinct, authorized approver, checked here in
     * the service rather than only at the Policy layer, same as `ApprovalService`.
     *
     * @param  numeric-string  $newCumulative
     */
    private function assertWithinTolerance(
        RequisitionItem $line,
        string $newCumulative,
        ?string $remark,
        ?int $overageApprovedBy,
    ): void {
        if (bccomp($newCumulative, $line->qty_requested_base, self::SCALE) <= 0) {
            return;
        }

        if (trim((string) $remark) === '') {
            throw new ExcessiveIssueQuantityException('จ่ายเกินจำนวนที่ขอต้องระบุหมายเหตุ (BR-04)');
        }

        $overage = bcsub($newCumulative, $line->qty_requested_base, self::SCALE);
        $overagePercent = bcdiv($overage, $line->qty_requested_base, self::SCALE + 2);

        if (bccomp($overagePercent, self::OVERAGE_APPROVAL_THRESHOLD, self::SCALE + 2) <= 0) {
            return;
        }

        if ($overageApprovedBy === null) {
            throw new ExcessiveIssueQuantityException('จ่ายเกิน 10% ต้องได้รับอนุมัติจากหัวหน้าห้องปฏิบัติการ (BR-04)');
        }

        $approver = User::find($overageApprovedBy);
        $isLabManager = $approver?->roles->flatMap(fn ($role) => $role->permissions)->contains('code', 'requisition.issue_override') ?? false;
        if (! $isLabManager) {
            throw new ExcessiveIssueQuantityException('ผู้อนุมัติการจ่ายเกิน 10% ต้องเป็นหัวหน้าห้องปฏิบัติการ (BR-04)');
        }
    }

    /** BR-01: APPROVED → PARTIALLY_ISSUED, or → ISSUED once every line's `qty_issued_base` meets its request. */
    private function advanceRequisitionStatus(Requisition $requisition): void
    {
        $requisition->load('items');
        $fullyIssued = $requisition->items->every(
            fn (RequisitionItem $item) => bccomp($item->qty_issued_base, $item->qty_requested_base, self::SCALE) >= 0
        );

        $event = $fullyIssued ? 'issueFull' : 'issuePartial';
        $requisition->status = $this->state->apply($requisition->status, $event, $requisition->requester_status);
        if ($fullyIssued) {
            $requisition->completed_at = now();
        }
        $requisition->save();
    }
}
