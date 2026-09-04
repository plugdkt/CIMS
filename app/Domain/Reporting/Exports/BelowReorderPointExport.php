<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\Item;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * §7.8 "สารคงคลังต่ำกว่าจุดสั่งซื้อ": items whose current balance (latest `stock_ledger.
 * balance_base`, same figure the requisition create form's real-time balance uses) has
 * dropped below `reorder_point_base`. Balance is global per item — spec's own schema
 * has no per-lab stock split — so the `lab` filter narrows to items that have at least one
 * container physically in that lab (via `containers.location_id` → `locations.lab_id`),
 * not a lab-scoped balance.
 */
final class BelowReorderPointExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly ?int $labId = null)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $items = Item::query()
            ->where('is_active', true)
            ->where('reorder_point_base', '>', 0)
            ->when($this->labId !== null, fn ($q) => $q->whereHas(
                'containers',
                fn ($c) => $c->whereHas('location', fn ($l) => $l->where('lab_id', $this->labId)),
            ))
            ->with('baseUnit')
            ->get()
            ->filter(function (Item $item) {
                $balance = StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000';

                return bccomp($balance, $item->reorder_point_base, 6) < 0;
            });

        return $items->map(fn (Item $item) => $this->rowToArray($item));
    }

    /** @return array<int, string> */
    private function rowToArray(Item $item): array
    {
        $balance = StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000';
        $unit = $item->baseUnit()->firstOrFail();

        return [
            CsvInjectionGuard::sanitize($item->item_code),
            CsvInjectionGuard::sanitize($item->name_th),
            $balance.' '.$unit->code,
            $item->reorder_point_base.' '.$unit->code,
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_item_code'),
            __('reports.col_item'),
            __('reports.col_current_balance'),
            __('reports.col_reorder_point'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.below_reorder_title'), 0, 31);
    }
}
