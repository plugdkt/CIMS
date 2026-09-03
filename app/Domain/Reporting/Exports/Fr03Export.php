<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\DTO\LedgerRow;
use App\Domain\Inventory\Services\LedgerQueryService;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** FR-LG-05: the Excel twin of {@see \App\Domain\Reporting\Services\Fr03PdfService} — same rows, same unit. */
final class Fr03Export implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Item $item,
        private readonly LedgerFilter $filter,
        private readonly Unit $displayUnit,
    ) {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $queryService = app(LedgerQueryService::class);
        $rows = $queryService->formatRows(
            $queryService->query($this->item, $this->filter)->get(),
            $this->item,
            $this->displayUnit,
        );

        $unitCode = $this->displayUnit->code;

        return $rows->map(fn (LedgerRow $row) => $this->rowToArray($row, $unitCode));
    }

    /** @return array<int, string> */
    private function rowToArray(LedgerRow $row, string $unitCode): array
    {
        return [
            $row->txnDate->format('d/m/Y'),
            (string) __('ledger.txn_'.strtolower($row->txnType)),
            $row->issuerName ?? '',
            $row->receiverName ?? '',
            $row->qtyIn !== null ? "{$row->qtyIn} {$unitCode}" : '',
            $row->qtyOut !== null ? "{$row->qtyOut} {$unitCode}" : '',
            "{$row->balance} {$unitCode}",
            $row->signed ? (string) __('ledger.signed_yes') : '',
            $row->remark ?? '',
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('ledger.col_date'),
            __('ledger.col_txn_type'),
            __('ledger.col_issuer'),
            __('ledger.col_receiver'),
            __('ledger.col_in'),
            __('ledger.col_out'),
            __('ledger.col_balance'),
            __('ledger.col_signature'),
            __('ledger.col_remark'),
        ];
    }

    public function title(): string
    {
        return mb_substr($this->item->item_code, 0, 31);
    }
}
