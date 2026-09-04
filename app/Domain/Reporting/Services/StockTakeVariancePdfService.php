<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Models\StockTake;
use App\Models\StockTakeLine;
use Illuminate\Support\Collection;
use Mpdf\Output\Destination;

/** §7.8 "ผลตรวจนับ + ผลต่าง": the PDF twin of {@see \App\Domain\Reporting\Exports\StockTakeVarianceExport}. */
final class StockTakeVariancePdfService
{
    public function render(StockTake $stockTake): string
    {
        $lines = $stockTake->lines()->with(['container.item', 'countedBy'])->get();

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->SetTitle((string) __('reports.stock_take_variance_title').' — '.$stockTake->doc_no);
        $mpdf->WriteHTML($this->buildHtml($stockTake, $lines));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @param  Collection<int, StockTakeLine>  $lines */
    private function buildHtml(StockTake $stockTake, Collection $lines): string
    {
        $title = e(__('reports.stock_take_variance_title').' — '.$stockTake->doc_no);
        $lab = $stockTake->lab()->firstOrFail();
        $labLine = e($lab->name_th.' · '.$stockTake->count_date->format('d/m/Y'));

        $bodyRows = $lines->isEmpty()
            ? '<tr><td colspan="6" style="text-align:center;padding:8px;">'.e(__('reports.no_results')).'</td></tr>'
            : $lines->map(fn (StockTakeLine $line) => $this->rowHtml($line))->implode('');

        return <<<HTML
            <style>
                body { font-size: 9pt; }
                h1 { font-size: 13pt; margin-bottom: 2px; }
                p.sub { font-size: 9pt; color: #555; margin: 0 0 8px; }
                table.report { border-collapse: collapse; width: 100%; }
                table.report th, table.report td { border: 0.2mm solid #999; padding: 3px 4px; font-size: 8pt; }
                table.report th { background-color: #F0E0FC; text-align: left; }
                .num { text-align: right; }
            </style>
            <h1>{$title}</h1>
            <p class="sub">{$labLine}</p>
            <table class="report">
                <thead>
                    <tr>
                        <th>{$this->col('col_item')}</th>
                        <th>{$this->col('col_barcode')}</th>
                        <th class="num">{$this->col('col_system_qty')}</th>
                        <th class="num">{$this->col('col_counted_qty')}</th>
                        <th class="num">{$this->col('col_diff')}</th>
                        <th>{$this->col('col_reason')}</th>
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

    private function rowHtml(StockTakeLine $line): string
    {
        $container = $line->container()->firstOrFail();
        $item = $container->item()->firstOrFail();

        $name = e($item->name_th);
        $barcode = e($container->barcode);
        $system = e((string) $line->system_qty_base);
        $counted = e($line->counted_qty_base !== null ? (string) $line->counted_qty_base : (string) __('reports.not_counted'));
        $diff = e($line->diff_base !== null ? (string) $line->diff_base : '');
        $reason = e($line->reason ?? '');

        return <<<HTML
            <tr>
                <td>{$name}</td>
                <td>{$barcode}</td>
                <td class="num">{$system}</td>
                <td class="num">{$counted}</td>
                <td class="num">{$diff}</td>
                <td>{$reason}</td>
            </tr>
            HTML;
    }
}
