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
use Illuminate\Support\Collection;

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
        $this->assertWithinTolerance($requisition, $line, $newCumulative, $remark, $overageApprovedBy);

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
     * User-requested 2026-09-23: "ให้ระบบแนะนำ/จ่ายจากหลายขวดในคลิกเดียว" — one submission that
     * dispenses a single total quantity across several containers automatically, instead of
     * the issuer repeating {@see issue()} once per container by hand. Greedily fills
     * `$containersInOrder` in the order given (the caller passes FEFO order via
     * `FefoContainerSelector` — this method has no opinion on ordering, only on how much to
     * take from each), stopping once the requested total is met or the containers run out.
     *
     * One receiver confirmation (`$signatureHash`/`$signatureImagePath`) covers the whole
     * batch — it is the same physical hand-off event, just split across containers because
     * no single one held enough. Each container still gets its own `issue_transactions` row
     * and its own {@see issue()} call, so BR-04's tolerance check runs exactly as it would
     * for a manual multi-step issue: against the line's running cumulative, per call — a
     * batch that crosses the ceiling is caught the same way a single manual issue would be,
     * not silently waved through because it happened inside one loop.
     *
     * Under-fulfillment (not enough total stock across every eligible container) is not an
     * error — it issues whatever is actually available and stops; the caller reports how
     * much was actually dispensed against how much was asked for.
     *
     * @param  Collection<int, Container>  $containersInOrder  eligible only, already ordered — this method doesn't sort
     * @param  numeric-string  $qtyRequested  in $unit, the TOTAL to dispense across containers
     * @return list<IssueTransaction>
     */
    public function issueAcrossContainers(
        RequisitionItem $line,
        Collection $containersInOrder,
        string $qtyRequested,
        Unit $unit,
        User $issuer,
        User $receiver,
        string $signatureHash,
        ?string $signatureImagePath = null,
        ?string $remark = null,
        ?int $overageApprovedBy = null,
    ): array {
        $item = $line->item()->firstOrFail();
        $neededBase = $this->converter->toItemBase($item, $unit, $qtyRequested);
        $itemBaseUnit = $item->baseUnit()->firstOrFail();

        /** @var list<IssueTransaction> $transactions */
        $transactions = [];

        foreach ($containersInOrder as $container) {
            if (bccomp($neededBase, '0', self::SCALE) <= 0) {
                break;
            }

            /** @var numeric-string $containerRemaining */
            $containerRemaining = $container->remaining_qty_base;
            $takeBase = bccomp($containerRemaining, $neededBase, self::SCALE) < 0
                ? $containerRemaining
                : $neededBase;

            if (bccomp($takeBase, '0', self::SCALE) <= 0) {
                continue;
            }

            // Recorded in the item's own base unit, not $unit — $takeBase is already in
            // those terms (container.remaining_qty_base always is), so this is the one
            // unit where handing it straight to issue() needs no further conversion at all,
            // rather than converting it out to $unit only for issue() to convert it right
            // back via toItemBase() a moment later.
            $transactions[] = $this->issue(
                $line,
                $container,
                $takeBase,
                $itemBaseUnit,
                $issuer,
                $receiver,
                $signatureHash,
                $signatureImagePath,
                $remark,
                $overageApprovedBy,
            );

            $neededBase = bcsub($neededBase, $takeBase, self::SCALE);
        }

        return $transactions;
    }

    /**
     * BR-04: up to 10% over the approved ceiling just needs a remark; past 10% the
     * approver must actually hold `requisition.issue_override` (LAB_MANAGER per
     * PermissionSeeder) — mirrors BR-06's distinct, authorized approver, checked here in
     * the service rather than only at the Policy layer, same as `ApprovalService`.
     *
     * User-requested 2026-09-23: the ceiling is `RequisitionItem::approvedCeilingBase()`
     * (a warehouse manager's approved quantity, if they set one lower than requested — or
     * `qty_requested_base` otherwise), not `qty_requested_base` directly. Otherwise a line
     * approved for 50 of a 100 request could still be issued all the way up to 100 before
     * this check ever triggered, silently ignoring the reduced approval.
     *
     * @param  numeric-string  $newCumulative
     */
    private function assertWithinTolerance(
        Requisition $requisition,
        RequisitionItem $line,
        string $newCumulative,
        ?string $remark,
        ?int $overageApprovedBy,
    ): void {
        $ceiling = $line->approvedCeilingBase();

        if (bccomp($newCumulative, $ceiling, self::SCALE) <= 0) {
            return;
        }

        if (trim((string) $remark) === '') {
            throw new ExcessiveIssueQuantityException('จ่ายเกินจำนวนที่ขอต้องระบุหมายเหตุ (BR-04)');
        }

        $overage = bcsub($newCumulative, $ceiling, self::SCALE);

        // A zero ceiling (a line approved for none of it) has no percentage to compute
        // against — bcdiv() by zero throws. Any issuance at all against it is already past
        // the point a percentage-based tolerance is meaningful, so it goes straight to
        // requiring an override, the same as a percentage over the threshold would.
        if (bccomp($ceiling, '0', self::SCALE) > 0) {
            $overagePercent = bcdiv($overage, $ceiling, self::SCALE + 2);

            if (bccomp($overagePercent, self::OVERAGE_APPROVAL_THRESHOLD, self::SCALE + 2) <= 0) {
                return;
            }
        }

        if ($overageApprovedBy === null) {
            throw new ExcessiveIssueQuantityException('จ่ายเกิน 10% ต้องได้รับอนุมัติจากหัวหน้าสาขาวิชาหรือผู้ดูแลคลัง (BR-04)');
        }

        $approver = User::find($overageApprovedBy);
        if ($approver === null) {
            throw new ExcessiveIssueQuantityException('ผู้อนุมัติการจ่ายเกิน 10% ต้องเป็นหัวหน้าสาขาวิชาหรือผู้ดูแลคลัง (BR-04)');
        }

        $isLabManager = $approver->roles->flatMap(fn ($role) => $role->permissions)->contains('code', 'requisition.issue_override');
        if (! $isLabManager) {
            throw new ExcessiveIssueQuantityException('ผู้อนุมัติการจ่ายเกิน 10% ต้องเป็นหัวหน้าสาขาวิชาหรือผู้ดูแลคลัง (BR-04)');
        }

        if ($approver->isBranchManager() && $approver->lab_id !== $requisition->lab_id) {
            throw new ExcessiveIssueQuantityException('ผู้อนุมัติต้องเป็นหัวหน้าสาขาวิชาหรือผู้ดูแลคลังของสาขาที่ยื่นใบเบิกนี้ (BR-04)');
        }
    }

    /** BR-01: APPROVED → PARTIALLY_ISSUED, or → ISSUED once every line's `qty_issued_base` meets its request. */
    private function advanceRequisitionStatus(Requisition $requisition): void
    {
        $requisition->load('items');
        // User-requested 2026-09-23: "fully issued" means fully issued against what was
        // actually approved, not what was originally requested — otherwise a line approved
        // for 50 of a 100 request could never leave PARTIALLY_ISSUED once all 50 is out.
        $fullyIssued = $requisition->items->every(
            fn (RequisitionItem $item) => bccomp($item->qty_issued_base, $item->approvedCeilingBase(), self::SCALE) >= 0
        );

        $event = $fullyIssued ? 'issueFull' : 'issuePartial';
        $requisition->status = $this->state->apply($requisition->status, $event, $requisition->requester_status);
        if ($fullyIssued) {
            $requisition->completed_at = now();
        }
        $requisition->save();
    }
}
