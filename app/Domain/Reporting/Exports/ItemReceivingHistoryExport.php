<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\Item;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * User-requested 2026-09-22: "ในระบบเติมสต็อก เรามีช่องหมายเหตุ แหล่งที่มาอยู่นะครับ ซึ่งตรงนี้เราตกลงกันแล้ว
 * ว่าจะใช้เป็นเลขที่ใบเบิก จากระบบ IMS ครับ และรายงานสารแต่ละตัวเนี่ย เราต้องเอาแนบท้ายรายงานตามใบเบิกนี้ด้วยครับ"
 * — the receiving side of {@see ItemIssueHistoryExport}'s stock-card report: every RECEIVE row
 * for one item, with `stock_ledger.remark` carrying the IMS requisition number the stock-in
 * form's "หมายเหตุ / แหล่งที่มา" field was agreed to hold. Appended as this report's second
 * sheet/section, not a separate menu item — this is the receiving half of the same stock card.
 */
final class ItemReceivingHistoryExport implements FromCollection, WithHeadings, WithTitle
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
     * Every RECEIVE row for this item, oldest first, shared with the on-screen dashboard.
     *
     * @return Collection<int, StockLedger>
     */
    public function results(): Collection
    {
        return StockLedger::query()
            ->where('item_id', $this->item->id)
            ->where('txn_type', 'RECEIVE')
            ->with('creator')
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

    /** @return array<int, string> */
    private function rowToArray(StockLedger $row): array
    {
        $creator = $row->creator()->first();
        $creatorName = $creator === null ? '' : $creator->full_name;

        return [
            $row->txn_date->format('d/m/Y'),
            CsvInjectionGuard::sanitize($row->remark ?? ''),
            CsvInjectionGuard::sanitize($creatorName),
            ItemIssueHistoryExport::trimQty((string) $row->qty_in_base),
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_date'),
            __('reports.col_ims_doc_no'),
            __('reports.col_received_by'),
            __('reports.col_qty_received'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.item_receiving_history_title'), 0, 31);
    }
}
