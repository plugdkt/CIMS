<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\Container;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** §7.8 "สารใกล้หมดอายุ": active containers whose expiry_date falls within the given range. */
final class ExpiringStockExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly DateRangeFilter $filter, private readonly ?int $labId = null)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $rows = Container::query()
            ->whereIn('status', ['SEALED', 'IN_USE', 'QUARANTINE'])
            ->whereNotNull('expiry_date')
            ->when($this->filter->dateFrom, fn ($q, $from) => $q->whereDate('expiry_date', '>=', $from))
            ->when($this->filter->dateTo, fn ($q, $to) => $q->whereDate('expiry_date', '<=', $to))
            ->when($this->labId !== null, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('lab_id', $this->labId)))
            ->with(['item', 'location.lab'])
            ->orderBy('expiry_date')
            ->get();

        return $rows->map(fn (Container $row) => $this->rowToArray($row));
    }

    /** @return array<int, string> */
    private function rowToArray(Container $row): array
    {
        $item = $row->item()->firstOrFail();

        return [
            CsvInjectionGuard::sanitize($item->name_th),
            CsvInjectionGuard::sanitize($item->item_code),
            CsvInjectionGuard::sanitize($row->barcode),
            CsvInjectionGuard::sanitize($row->lot_no ?? ''),
            $row->expiry_date?->format('d/m/Y') ?? '',
            (string) $row->remaining_qty_base,
            CsvInjectionGuard::sanitize($this->labNameFor($row)),
            (string) __('reports.status_'.strtolower($row->status)),
        ];
    }

    /**
     * `location_id`/`locations.lab_id` are both nullable, so this genuinely can be empty
     * — written as an explicit `if` (not `?->`/`??`) since PHPStan's nullsafe inference
     * for chained relation access is unreliable in either direction (see CLAUDE.md).
     */
    private function labNameFor(Container $container): string
    {
        $location = $container->location()->first();
        if ($location === null) {
            return '';
        }

        $lab = $location->lab()->first();

        return $lab === null ? '' : $lab->name_th;
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_item'),
            __('reports.col_item_code'),
            __('reports.col_barcode'),
            __('reports.col_lot_no'),
            __('reports.col_expiry_date'),
            __('reports.col_remaining_qty'),
            __('reports.col_lab'),
            __('reports.col_status'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.expiring_stock_title'), 0, 31);
    }
}
