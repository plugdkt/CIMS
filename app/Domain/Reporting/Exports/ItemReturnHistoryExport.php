<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * User-reported 2026-09-22: a real return scenario (received 250, issued 100 across two
 * dispensings, 50 of it returned, balance correctly 200) made the report look broken —
 * "เบิก 100 คงเหลือ 200 ซึ่งมันเกินจากที่รับเข้าไป" (250) — because nothing in the report showed the
 * return that actually explains it: 250 received − 100 issued + 50 returned = 200, exactly
 * right. The dispensing and balance numbers were never wrong; the report was just missing
 * this whole category of movement. This is the third section of the same stock card,
 * alongside {@see ItemIssueHistoryExport} and {@see ItemReceivingHistoryExport}.
 *
 * Returns aren't in `issue_transactions` (that table only ever records the original
 * dispensing) — they're `stock_ledger` RETURN rows written by `ReturnService`/
 * `LedgerService::return()`, tagged `ref_type = 'REQUISITION'`/`ref_id` = the requisition,
 * not a specific `issue_transactions` row (a line can be issued more than once before a
 * single return, so there's no one dispensing event a return could be attributed to).
 */
final class ItemReturnHistoryExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Item $item,
        private readonly DateRangeFilter $period,
        private readonly ?int $labId = null,
    ) {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        return $this->results()->map(fn (StockLedger $row) => $this->rowToArray($row));
    }

    /**
     * Every RETURN row for this item, oldest first, shared with the on-screen dashboard.
     *
     * @return Collection<int, StockLedger>
     */
    public function results(): Collection
    {
        return StockLedger::query()
            ->where('item_id', $this->item->id)
            ->where('txn_type', 'RETURN')
            ->when(
                $this->labId !== null,
                fn ($q) => $q->whereHas('container', fn ($c) => $c->whereHas('location', fn ($l) => $l->where('lab_id', $this->labId))),
            )
            ->when($this->period->dateFrom, fn ($q, $from) => $q->whereDate('txn_date', '>=', $from))
            ->when($this->period->dateTo, fn ($q, $to) => $q->whereDate('txn_date', '<=', $to))
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get();
    }

    /** The sum of exactly the rows `results()` returns. */
    public function totalReturned(): string
    {
        $total = '0.000000';

        foreach ($this->results() as $row) {
            /** @var numeric-string $qty */
            $qty = $row->qty_in_base;
            $total = bcadd($total, $qty, 6);
        }

        return $total;
    }

    /** @return array<int, string> */
    private function rowToArray(StockLedger $row): array
    {
        $requisition = $row->ref_type === 'REQUISITION' && $row->ref_id !== null
            ? Requisition::find($row->ref_id)
            : null;
        $requester = $requisition === null ? null : $requisition->requester()->first();
        $requesterName = $requester === null ? '' : $requester->full_name;

        return [
            $row->txn_date->format('d/m/Y'),
            CsvInjectionGuard::sanitize((string) ($row->ref_doc_no ?? '')),
            CsvInjectionGuard::sanitize($requesterName),
            $this->qtyWithUnit((string) $row->qty_in_base),
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_date'),
            __('reports.col_doc_no'),
            __('reports.col_requester'),
            __('reports.col_qty_returned'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.item_return_history_title'), 0, 31);
    }

    /** Same trim-and-append-unit convention as the issuing/receiving sides of this report. */
    private function qtyWithUnit(string $value): string
    {
        $unit = $this->item->baseUnit?->code;

        return trim(ItemIssueHistoryExport::trimQty($value).' '.$unit);
    }
}
