<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** §7.8 "สารควบคุม": every ledger movement of a controlled item (`items.is_controlled`) in the given range. */
final class ControlledSubstancesExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly DateRangeFilter $filter, private readonly ?int $labId = null)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $rows = $this->query()->with(['item', 'creator'])->orderBy('txn_date')->get();

        return $rows->map(fn (StockLedger $row) => $this->rowToArray($row));
    }

    /** @return \Illuminate\Database\Eloquent\Builder<StockLedger> */
    private function query()
    {
        return StockLedger::query()
            ->whereHas('item', fn ($q) => $q->where('is_controlled', true))
            ->when($this->filter->dateFrom, fn ($q, $from) => $q->whereDate('txn_date', '>=', $from))
            ->when($this->filter->dateTo, fn ($q, $to) => $q->whereDate('txn_date', '<=', $to))
            ->when(
                $this->labId !== null,
                fn ($q) => $q->whereHas('container', fn ($c) => $c->whereHas('location', fn ($l) => $l->where('lab_id', $this->labId))),
            );
    }

    /** @return array<int, string> */
    private function rowToArray(StockLedger $row): array
    {
        $item = $row->item()->firstOrFail();
        $creator = $row->creator()->firstOrFail();

        return [
            $row->txn_date->format('d/m/Y'),
            CsvInjectionGuard::sanitize($item->name_th),
            CsvInjectionGuard::sanitize((string) $item->control_class),
            (string) __('ledger.txn_'.strtolower($row->txn_type)),
            (string) $row->qty_in_base,
            (string) $row->qty_out_base,
            (string) $row->balance_base,
            CsvInjectionGuard::sanitize($creator->full_name),
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_date'),
            __('reports.col_item'),
            __('reports.col_control_class'),
            __('ledger.col_txn_type'),
            __('reports.col_qty_in'),
            __('reports.col_qty_out'),
            __('reports.col_balance'),
            __('reports.col_created_by'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.controlled_substances_title'), 0, 31);
    }
}
