<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\IssueTransaction;
use App\Models\Item;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * User-requested 2026-09-22: "รายงานการขอเบิกสารเคมีแต่ละตัว ว่าใครเบิก วันที่เบิก จำนวนเท่าไหร่
 * และสรุปยอดคงเหลือ" — one chemical at a time: every dispensing against it (who, when, how
 * much) plus what is left now. The stock-card view of a single item.
 *
 * Quantities are what was actually dispensed, not what was asked for (user-decided the same
 * day): those are the movements that produced the balance shown alongside them, so the
 * detail lines and the summary always reconcile. A requisition that is approved but not yet
 * issued deliberately does not appear — it has moved no stock.
 *
 * Balance is global per item (spec's schema has no per-lab stock split, the same fact every
 * other §7.8 report records); the `lab` filter narrows which *requisitions* are listed, and
 * `totalIssued()` sums exactly the rows listed, so a branch-scoped view stays internally
 * consistent even though the balance beside it is the item's overall figure.
 */
final class ItemIssueHistoryExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Item $item,
        private readonly DateRangeFilter $period,
        private readonly ?int $labId = null,
    ) {
    }

    /** For a PDF twin that needs the item's own fields (name, brand, grade, …) alongside the rows. */
    public function itemFor(): Item
    {
        return $this->item;
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        return $this->results()->map(fn (IssueTransaction $row) => $this->rowToArray($row));
    }

    /**
     * Every dispensing of this item, oldest first, shared with the on-screen dashboard.
     *
     * @return Collection<int, IssueTransaction>
     */
    public function results(): Collection
    {
        return IssueTransaction::query()
            ->with(['requisitionItem.requisition.requester', 'receiver'])
            ->whereHas('requisitionItem', function ($q) {
                $q->where('item_id', $this->item->id);

                if ($this->labId !== null) {
                    $q->whereHas('requisition', fn ($r) => $r->where('lab_id', $this->labId));
                }
            })
            ->when($this->period->dateFrom, fn ($q, $from) => $q->whereDate('issued_at', '>=', $from))
            ->when($this->period->dateTo, fn ($q, $to) => $q->whereDate('issued_at', '<=', $to))
            // `id` breaks the tie: two dispensings in the same second would otherwise come
            // back in whatever order the engine chose, so the same report could list them
            // differently between runs.
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();
    }

    /** What is physically left of this item right now, across every branch. */
    public function remainingBalance(): string
    {
        return StockLedger::where('item_id', $this->item->id)
            ->orderByDesc('id')
            ->value('balance_base') ?? '0.000000';
    }

    /** The sum of exactly the rows `results()` returns, so detail and summary reconcile. */
    public function totalIssued(): string
    {
        $total = '0.000000';

        foreach ($this->results() as $row) {
            /** @var numeric-string $qty */
            $qty = $row->qty_issued_base;
            $total = bcadd($total, $qty, 6);
        }

        return $total;
    }

    /**
     * User-reported 2026-09-22: exports still showed the full `DECIMAL(18,6)` precision
     * (e.g. `10.000000`) — the same trim-trailing-zeros convention already applied to the
     * on-screen table (this Livewire view) and the FR-RQ-05 balance display, just missing
     * from the Excel/PDF output. Display only — `totalIssued()`/`remainingBalance()` (used
     * for the actual reconciliation, and by callers that need the full value) are untouched.
     */
    public static function trimQty(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }

    /** @return array<int, string> */
    private function rowToArray(IssueTransaction $row): array
    {
        $requisitionItem = $row->requisitionItem()->firstOrFail();
        $requisition = $requisitionItem->requisition()->firstOrFail();
        $requester = $requisition->requester()->firstOrFail();

        return [
            $row->issued_at->format('d/m/Y'),
            CsvInjectionGuard::sanitize($requisition->doc_no),
            CsvInjectionGuard::sanitize($requester->full_name),
            CsvInjectionGuard::sanitize($requisition->faculty ?? ''),
            self::trimQty((string) $row->qty_issued_base),
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_date'),
            __('reports.col_doc_no'),
            __('reports.col_requester'),
            __('reports.col_faculty'),
            __('reports.col_qty_issued'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.item_issue_history_title'), 0, 31);
    }
}
