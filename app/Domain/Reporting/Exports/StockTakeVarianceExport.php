<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** §7.8 "ผลตรวจนับ + ผลต่าง": every line of one stock take round, filtered by round. */
final class StockTakeVarianceExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly StockTake $stockTake)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $lines = $this->stockTake->lines()->with(['container.item', 'countedBy'])->get();

        return $lines->map(fn (StockTakeLine $line) => $this->rowToArray($line));
    }

    /** @return array<int, string> */
    private function rowToArray(StockTakeLine $line): array
    {
        $container = $line->container()->firstOrFail();
        $item = $container->item()->firstOrFail();
        $countedBy = $line->counted_by !== null ? $line->countedBy()->first() : null;

        return [
            CsvInjectionGuard::sanitize($item->name_th),
            CsvInjectionGuard::sanitize($container->barcode),
            (string) $line->system_qty_base,
            $line->counted_qty_base !== null ? (string) $line->counted_qty_base : (string) __('reports.not_counted'),
            $line->diff_base !== null ? (string) $line->diff_base : '',
            CsvInjectionGuard::sanitize($line->reason ?? ''),
            $countedBy !== null ? CsvInjectionGuard::sanitize($countedBy->full_name) : '',
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_item'),
            __('reports.col_barcode'),
            __('reports.col_system_qty'),
            __('reports.col_counted_qty'),
            __('reports.col_diff'),
            __('reports.col_reason'),
            __('reports.col_counted_by'),
        ];
    }

    public function title(): string
    {
        return mb_substr($this->stockTake->doc_no, 0, 31);
    }
}
