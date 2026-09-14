<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidAdjustmentException;
use App\Models\Container;
use App\Models\StockLedger;
use App\Models\User;
use RuntimeException;

/**
 * FR-LG-07 / BR-06: a direct, single-step ledger adjustment. Spec's schema has no
 * "pending adjustment request" table (unlike stock take/disposal's staged request →
 * approve shape) — BR-06's own wording ("ต้องมี approved_by เป็น LAB_MANAGER ที่ไม่ใช่
 * คนเดียวกับ created_by") describes one action naming two people at once, so this is one
 * form submission, not a two-phase workflow. `LedgerService::adjust()` (T-021) already
 * enforces "approver ≠ creator"; this class adds the other half BR-06 implies but that
 * method alone can't check — the named approver must actually **be** a LAB_MANAGER
 * (hold `ledger.adjust`), not merely a different user id.
 */
final class AdjustmentService
{
    public function __construct(private readonly LedgerService $ledgerService)
    {
    }

    /**
     * @param  numeric-string  $signedQtyBase
     */
    public function adjust(Container $container, string $signedQtyBase, string $remark, User $creator, User $approver): StockLedger
    {
        $isAuthorizedApprover = $approver->roles->flatMap(fn ($role) => $role->permissions)->contains('code', 'ledger.adjust');
        if (! $isAuthorizedApprover) {
            throw new InvalidAdjustmentException('ผู้อนุมัติต้องเป็นหัวหน้าสาขาวิชา (BR-06)');
        }

        if ($approver->hasRole('LAB_MANAGER')) {
            $containerLabId = $this->labIdFor($container);
            if ($containerLabId !== null && $containerLabId !== $approver->lab_id) {
                throw new InvalidAdjustmentException('ผู้อนุมัติต้องเป็นหัวหน้าสาขาวิชาของสาขาที่ภาชนะนี้ตั้งอยู่');
            }
        }

        $item = $container->item()->firstOrFail();

        // `base_unit_id` is nullable on Item (a brand-new item added via stock-in may not
        // have one yet), but a container that physically exists to be adjusted must have
        // been received against an item that already had one assigned.
        if ($item->base_unit_id === null) {
            throw new RuntimeException("Item #{$item->id} has no base_unit_id but has a real container to adjust.");
        }

        return $this->ledgerService->adjust($container->id, $signedQtyBase, new LedgerEntryData(
            displayUnitId: $item->base_unit_id,
            createdBy: $creator->id,
            refType: 'ADJUSTMENT',
            remark: $remark,
            approvedBy: $approver->id,
        ));
    }

    /**
     * `location_id`/`locations.lab_id` are both nullable, so this genuinely can be empty
     * — written as an explicit `if` (not `?->`/`??`) since PHPStan's nullsafe inference
     * for chained relation access is unreliable in either direction (see CLAUDE.md).
     */
    private function labIdFor(Container $container): ?int
    {
        $location = $container->location()->first();
        if ($location === null) {
            return null;
        }

        $lab = $location->lab()->first();

        return $lab?->id;
    }
}
