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
 * "สรุปคงเหลือ/การใช้รายสารเคมี": every item with real ledger history — how much was
 * issued in the given period and how much is left right now — sorted lowest-remaining
 * first, so the item closest to running out is the one a scientist sees first and
 * knows to go restock from the central warehouse. Balance is global per item (spec's
 * schema has no per-lab stock split, same fact already noted for the other §7.8
 * reports); the `lab` filter narrows to items that have at least one container
 * physically in that lab, not a lab-scoped balance.
 */
final class ItemStockSummaryExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly DateRangeFilter $usagePeriod, private readonly ?int $labId = null)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        return $this->results()->map(fn (Item $item) => $this->rowToArray($item));
    }

    /**
     * Every active item with at least one real ledger row, lowest current balance first.
     *
     * @return Collection<int, Item>
     */
    public function results(): Collection
    {
        return Item::query()
            ->where('is_active', true)
            ->when($this->labId !== null, fn ($q) => $q->whereHas(
                'containers',
                fn ($c) => $c->whereHas('location', fn ($l) => $l->where('lab_id', $this->labId)),
            ))
            ->whereIn('id', StockLedger::select('item_id')->distinct())
            ->with('baseUnit')
            ->get()
            ->sortBy(fn (Item $item) => (float) $this->remainingBalance($item))
            ->values();
    }

    public function remainingBalance(Item $item): string
    {
        return StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000';
    }

    /** Total issued in the filter's date range (all time if both bounds are empty). */
    public function usedQuantity(Item $item): string
    {
        $sum = StockLedger::where('item_id', $item->id)
            ->where('txn_type', 'ISSUE')
            ->when($this->usagePeriod->dateFrom, fn ($q, $from) => $q->whereDate('txn_date', '>=', $from))
            ->when($this->usagePeriod->dateTo, fn ($q, $to) => $q->whereDate('txn_date', '<=', $to))
            ->sum('qty_out_base');

        return (string) $sum;
    }

    /** @return array<int, string> */
    private function rowToArray(Item $item): array
    {
        // PHPStan's nullsafe inference for this relation is unreliable in either
        // direction (see CLAUDE.md) — an explicit local + `if` is what actually works.
        $unit = $item->baseUnit;
        $unitCode = $unit === null ? '' : $unit->code;

        return [
            CsvInjectionGuard::sanitize($item->item_code),
            CsvInjectionGuard::sanitize($item->name_th),
            $this->usedQuantity($item).' '.$unitCode,
            $this->remainingBalance($item).' '.$unitCode,
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_item_code'),
            __('reports.col_item'),
            __('reports.col_used_qty'),
            __('reports.col_current_balance'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.item_stock_summary_title'), 0, 31);
    }
}
