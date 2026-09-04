<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidAdjustmentException;
use App\Models\Container;
use App\Models\StockLedger;
use App\Models\User;

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
            throw new InvalidAdjustmentException('ผู้อนุมัติต้องเป็นหัวหน้าห้องปฏิบัติการ (BR-06)');
        }

        $item = $container->item()->firstOrFail();

        return $this->ledgerService->adjust($container->id, $signedQtyBase, new LedgerEntryData(
            displayUnitId: $item->base_unit_id,
            createdBy: $creator->id,
            refType: 'ADJUSTMENT',
            remark: $remark,
            approvedBy: $approver->id,
        ));
    }
}
