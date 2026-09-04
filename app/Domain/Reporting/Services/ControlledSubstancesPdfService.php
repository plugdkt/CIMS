<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Mpdf\Output\Destination;

/** §7.8 "สารควบคุม": the PDF twin of {@see \App\Domain\Reporting\Exports\ControlledSubstancesExport}. */
final class ControlledSubstancesPdfService
{
    public function render(DateRangeFilter $filter): string
    {
        $rows = StockLedger::query()
            ->whereHas('item', fn ($q) => $q->where('is_controlled', true))
            ->when($filter->dateFrom, fn ($q, $from) => $q->whereDate('txn_date', '>=', $from))
            ->when($filter->dateTo, fn ($q, $to) => $q->whereDate('txn_date', '<=', $to))
            ->with(['item', 'creator'])
            ->orderBy('txn_date')
            ->get();

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->SetTitle((string) __('reports.controlled_substances_title'));
        $mpdf->WriteHTML($this->buildHtml($rows));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @param  Collection<int, StockLedger>  $rows */
    private function buildHtml(Collection $rows): string
    {
        $title = e(__('reports.controlled_substances_title'));
        $txnTypeLabel = e(__('ledger.col_txn_type'));

        $bodyRows = $rows->isEmpty()
            ? '<tr><td colspan="7" style="text-align:center;padding:8px;">'.e(__('reports.no_results')).'</td></tr>'
            : $rows->map(fn (StockLedger $row) => $this->rowHtml($row))->implode('');

        return <<<HTML
            <style>
                body { font-size: 9pt; }
                h1 { font-size: 13pt; margin-bottom: 8px; }
                table.report { border-collapse: collapse; width: 100%; }
                table.report th, table.report td { border: 0.2mm solid #999; padding: 3px 4px; font-size: 8pt; }
                table.report th { background-color: #F0E0FC; text-align: left; }
                .num { text-align: right; }
            </style>
            <h1>{$title}</h1>
            <table class="report">
                <thead>
                    <tr>
                        <th>{$this->col('col_date')}</th>
                        <th>{$this->col('col_item')}</th>
                        <th>{$this->col('col_control_class')}</th>
                        <th>{$txnTypeLabel}</th>
                        <th class="num">{$this->col('col_qty_in')}</th>
                        <th class="num">{$this->col('col_qty_out')}</th>
                        <th class="num">{$this->col('col_balance')}</th>
                    </tr>
                </thead>
                <tbody>
                    {$bodyRows}
                </tbody>
            </table>
            HTML;
    }

    private function col(string $key): string
    {
        return e(__('reports.'.$key));
    }

    private function rowHtml(StockLedger $row): string
    {
        $item = $row->item()->firstOrFail();

        $date = e($row->txn_date->format('d/m/Y'));
        $name = e($item->name_th);
        $controlClass = e((string) $item->control_class);
        $type = e((string) __('ledger.txn_'.strtolower($row->txn_type)));
        $in = e((string) $row->qty_in_base);
        $out = e((string) $row->qty_out_base);
        $balance = e((string) $row->balance_base);

        return <<<HTML
            <tr>
                <td>{$date}</td>
                <td>{$name}</td>
                <td>{$controlClass}</td>
                <td>{$type}</td>
                <td class="num">{$in}</td>
                <td class="num">{$out}</td>
                <td class="num">{$balance}</td>
            </tr>
            HTML;
    }
}
