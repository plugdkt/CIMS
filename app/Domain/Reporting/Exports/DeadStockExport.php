<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\Container;
use App\Models\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * §7.8 "Dead stock (ไม่เคลื่อนไหว > 12 เดือน)": active containers holding stock that no
 * `stock_ledger` row has referenced (by `container_id`) in over 12 months — evaluated
 * per container (not per item), since a container is the physically actionable unit
 * (the one you'd actually go dispose of or reallocate).
 */
final class DeadStockExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly ?int $labId = null)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $cutoff = now()->subMonths(12);

        $containers = Container::query()
            ->whereIn('status', ['SEALED', 'IN_USE', 'QUARANTINE'])
            ->where('remaining_qty_base', '>', 0)
            ->when($this->labId !== null, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('lab_id', $this->labId)))
            ->whereDoesntHave('stockLedgerRows', fn ($q) => $q->where('txn_date', '>=', $cutoff->toDateString()))
            ->with(['item', 'location.lab'])
            ->get();

        return $containers->map(fn (Container $row) => $this->rowToArray($row));
    }

    /** @return array<int, string> */
    private function rowToArray(Container $row): array
    {
        $item = $row->item()->firstOrFail();
        $labName = $this->labNameFor($row);
        $lastMovement = StockLedger::where('container_id', $row->id)->orderByDesc('txn_date')->value('txn_date');

        return [
            CsvInjectionGuard::sanitize($item->name_th),
            CsvInjectionGuard::sanitize($row->barcode),
            CsvInjectionGuard::sanitize($labName),
            (string) $row->remaining_qty_base,
            $lastMovement !== null ? Carbon::parse($lastMovement)->format('d/m/Y') : (string) __('reports.never_moved'),
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
            __('reports.col_barcode'),
            __('reports.col_lab'),
            __('reports.col_remaining_qty'),
            __('reports.col_last_movement'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.dead_stock_title'), 0, 31);
    }
}
