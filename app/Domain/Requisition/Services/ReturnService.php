<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Requisition\Exceptions\InvalidReturnException;
use App\Domain\Shared\UnitConverter;
use App\Models\Container;
use App\Models\IssueTransaction;
use App\Models\RequisitionItem;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * FR-ST-01 / BR-05: returning unused material back into the container it was actually
 * issued from. `LedgerService::return()` remains the only writer of `stock_ledger`; this
 * class owns BR-05's two checks and `requisition_items.qty_returned_base`.
 */
final class ReturnService
{
    private const SCALE = 6;

    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly UnitConverter $converter,
    ) {
    }

    /**
     * @param  numeric-string  $qtyReturned  in $unit, not yet converted to the item's base unit
     */
    public function return(
        RequisitionItem $line,
        Container $container,
        string $qtyReturned,
        Unit $unit,
        User $actor,
        ?string $remark = null,
    ): StockLedger {
        // BR-05: คืนได้เฉพาะรายการที่ qty_issued_base - qty_returned_base > 0
        $returnable = bcsub($line->qty_issued_base, $line->qty_returned_base, self::SCALE);
        if (bccomp($returnable, '0', self::SCALE) <= 0) {
            throw new InvalidReturnException('รายการนี้ไม่มียอดที่จ่ายไปแล้วเหลือให้คืน');
        }

        $item = $line->item()->firstOrFail();
        $requisition = $line->requisition()->firstOrFail();
        $qtyReturnedBase = $this->converter->toItemBase($item, $unit, $qtyReturned);

        if (bccomp($qtyReturnedBase, $returnable, self::SCALE) > 0) {
            throw new InvalidReturnException("คืนได้ไม่เกินยอดที่จ่ายไปแล้วคงเหลือ ({$returnable})");
        }

        // BR-05: คืนกลับเข้าภาชนะเดิม (container_id เดียวกับตอนจ่าย) เท่านั้น
        $wasIssuedFromThisContainer = IssueTransaction::where('requisition_item_id', $line->id)
            ->where('container_id', $container->id)
            ->exists();
        if (! $wasIssuedFromThisContainer) {
            throw new InvalidReturnException('คืนได้เฉพาะภาชนะที่จ่ายรายการนี้ออกไปเท่านั้น');
        }

        $row = $this->ledgerService->return($container->id, $qtyReturnedBase, new LedgerEntryData(
            displayUnitId: $unit->id,
            createdBy: $actor->id,
            refType: 'REQUISITION',
            refId: $requisition->id,
            refDocNo: $requisition->doc_no,
            issuerId: $actor->id,
            remark: $remark,
        ));

        $line->qty_returned_base = bcadd($line->qty_returned_base, $qtyReturnedBase, self::SCALE);
        $line->save();

        return $row;
    }

    /** @return Collection<int, Container> containers this line was ever issued from */
    public function issuedContainersFor(RequisitionItem $line): Collection
    {
        $containerIds = IssueTransaction::where('requisition_item_id', $line->id)->distinct()->pluck('container_id');

        return Container::whereIn('id', $containerIds)->get();
    }
}
