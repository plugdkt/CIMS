<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidDisposalException;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Models\Container;
use App\Models\Disposal;
use App\Models\User;
use RuntimeException;

/**
 * FR-ST-05: record + approve destroying/discarding material — reason, disposal method,
 * and an approver, exactly as spec asks. `LedgerService::dispose()` remains the only
 * writer of `stock_ledger`; this class owns the `disposals` request/approve workflow.
 */
final class DisposalService
{
    private const SCALE = 6;

    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly DocumentNumberGenerator $documentNumbers,
    ) {
    }

    /**
     * @param  numeric-string  $qtyBase
     * @param  'EXPIRED'|'CONTAMINATED'|'DAMAGED'|'WASTE'|'OTHER'  $reason
     */
    public function request(
        Container $container,
        string $qtyBase,
        string $reason,
        ?string $method,
        string $disposalDate,
        User $requester,
    ): Disposal {
        if (bccomp($qtyBase, $container->remaining_qty_base, self::SCALE) > 0) {
            throw new InvalidDisposalException("จำนวนที่ขอทำลายต้องไม่เกินยอดคงเหลือในภาชนะ (คงเหลือ {$container->remaining_qty_base})");
        }

        return Disposal::create([
            'doc_no' => $this->documentNumbers->next('DSP'),
            'container_id' => $container->id,
            'qty_base' => $qtyBase,
            'reason' => $reason,
            'method' => $method,
            'disposal_date' => $disposalDate,
            'requested_by' => $requester->id,
            'status' => 'PENDING',
        ]);
    }

    public function approve(Disposal $disposal, User $approver): Disposal
    {
        if ($disposal->status !== 'PENDING') {
            throw new InvalidDisposalException('อนุมัติได้เฉพาะรายการที่ยังรอพิจารณาเท่านั้น');
        }

        $container = $disposal->container()->firstOrFail();

        // Re-check against the container's *current* remaining stock — time has passed
        // since the request, and other transactions may have moved it since then.
        if (bccomp($disposal->qty_base, $container->remaining_qty_base, self::SCALE) > 0) {
            throw new InvalidDisposalException("จำนวนที่ขอทำลายเกินยอดคงเหลือปัจจุบันในภาชนะ (คงเหลือ {$container->remaining_qty_base})");
        }

        $item = $container->item()->firstOrFail();
        $methodNote = $disposal->method !== null ? " วิธีกำจัด: {$disposal->method}" : '';

        // `base_unit_id` is nullable on Item (a brand-new item added via stock-in may not
        // have one yet), but a container that physically exists to be disposed of must
        // have been received against an item that already had one assigned.
        if ($item->base_unit_id === null) {
            throw new RuntimeException("Item #{$item->id} has no base_unit_id but has a real container to dispose of.");
        }

        $this->ledgerService->dispose($container->id, $disposal->qty_base, new LedgerEntryData(
            displayUnitId: $item->base_unit_id,
            createdBy: $disposal->requested_by,
            refType: 'DISPOSAL',
            refId: $disposal->id,
            refDocNo: $disposal->doc_no,
            remark: "ทำลาย/ทิ้ง เหตุผล: {$disposal->reason}.{$methodNote}",
            approvedBy: $approver->id,
        ));

        $disposal->status = 'APPROVED';
        $disposal->approved_by = $approver->id;
        $disposal->approved_at = now();
        $disposal->save();

        return $disposal;
    }

    public function reject(Disposal $disposal, User $approver): Disposal
    {
        if ($disposal->status !== 'PENDING') {
            throw new InvalidDisposalException('ปฏิเสธได้เฉพาะรายการที่ยังรอพิจารณาเท่านั้น');
        }

        $disposal->status = 'REJECTED';
        $disposal->approved_by = $approver->id;
        $disposal->approved_at = now();
        $disposal->save();

        return $disposal;
    }
}
