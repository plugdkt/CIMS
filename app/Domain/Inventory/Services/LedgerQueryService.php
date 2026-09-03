<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\DTO\LedgerRow;
use App\Domain\Shared\UnitConverter;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * FR-LG-01..04: the shared query + display-unit transform behind the on-screen
 * ledger (T-024) and the F-03 PDF/Excel exports (T-025) — one place computing
 * "which rows, in which unit", so the export can never drift from what the
 * screen shows for the same filters.
 */
final class LedgerQueryService
{
    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /** @return Builder<StockLedger> */
    public function query(Item $item, LedgerFilter $filter): Builder
    {
        return StockLedger::where('item_id', $item->id)
            ->with(['issuer', 'receiver'])
            ->when(
                filled($filter->dateFrom),
                fn (Builder $q) => $q->whereDate('txn_date', '>=', $filter->dateFrom)
            )
            ->when(
                filled($filter->dateTo),
                fn (Builder $q) => $q->whereDate('txn_date', '<=', $filter->dateTo)
            )
            ->when(
                filled($filter->txnType),
                fn (Builder $q) => $q->where('txn_type', $filter->txnType)
            )
            ->when(filled($filter->receiverName), function (Builder $q) use ($filter) {
                $q->where(function (Builder $sub) use ($filter) {
                    $sub->where('receiver_name', 'like', "%{$filter->receiverName}%")
                        ->orWhereHas('receiver', fn (Builder $rq) => $rq->where('full_name', 'like', "%{$filter->receiverName}%"));
                });
            })
            ->when(filled($filter->containerBarcode), function (Builder $q) use ($filter) {
                $q->whereHas('container', fn (Builder $cq) => $cq->where('barcode', 'like', "%{$filter->containerBarcode}%"));
            })
            ->orderBy('id');
    }

    /**
     * @param  iterable<int, StockLedger>  $rows
     * @return Collection<int, LedgerRow>
     */
    public function formatRows(iterable $rows, Item $item, Unit $displayUnit): Collection
    {
        /** @var Unit $itemBaseUnit */
        $itemBaseUnit = $item->baseUnit()->firstOrFail();

        return collect($rows)->map(function (StockLedger $row) use ($itemBaseUnit, $displayUnit) {
            return new LedgerRow(
                id: $row->id,
                txnDate: $row->txn_date,
                txnType: $row->txn_type,
                issuerName: $row->issuer?->full_name,
                receiverName: $row->receiver_name ?? $row->receiver?->full_name,
                qtyIn: bccomp($row->qty_in_base, '0', 6) > 0
                    ? $this->toDisplayUnit($row->qty_in_base, $itemBaseUnit, $displayUnit)
                    : null,
                qtyOut: bccomp($row->qty_out_base, '0', 6) > 0
                    ? $this->toDisplayUnit($row->qty_out_base, $itemBaseUnit, $displayUnit)
                    : null,
                balance: $this->toDisplayUnit($row->balance_base, $itemBaseUnit, $displayUnit),
                signed: $row->signature_hash !== null,
                remark: $row->remark,
            );
        });
    }

    /**
     * @param  numeric-string  $qtyBase  in the item's own base unit
     * @return numeric-string
     */
    private function toDisplayUnit(string $qtyBase, Unit $itemBaseUnit, Unit $displayUnit): string
    {
        return $this->converter->fromBase($this->converter->toBase($qtyBase, $itemBaseUnit), $displayUnit);
    }
}
