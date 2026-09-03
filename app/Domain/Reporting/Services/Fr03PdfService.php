<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\DTO\LedgerRow;
use App\Domain\Inventory\Services\LedgerQueryService;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Mpdf\Output\Destination;

/** FR-LG-05: F-03 register as a printable PDF, respecting the same filters/display unit as the screen. */
final class Fr03PdfService
{
    public function __construct(private readonly LedgerQueryService $ledgerQuery)
    {
    }

    public function render(Item $item, LedgerFilter $filter, Unit $displayUnit): string
    {
        $rows = $this->ledgerQuery->formatRows(
            $this->ledgerQuery->query($item, $filter)->get(),
            $item,
            $displayUnit,
        );

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->SetTitle(__('ledger.title').' — '.$item->name_th);
        $mpdf->WriteHTML($this->buildHtml($item, $rows, $displayUnit));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @param Collection<int, LedgerRow> $rows */
    private function buildHtml(Item $item, Collection $rows, Unit $displayUnit): string
    {
        $title = e(__('ledger.title'));
        $header = $this->headerHtml($item);
        $unitCode = e($displayUnit->code);

        $bodyRows = $rows->isEmpty()
            ? '<tr><td colspan="9" style="text-align:center;padding:8px;">'.e(__('ledger.no_results')).'</td></tr>'
            : $rows->map(fn (LedgerRow $row) => $this->rowHtml($row, $unitCode))->implode('');

        return <<<HTML
            <style>
                body { font-size: 9pt; }
                h1 { font-size: 13pt; margin-bottom: 4px; }
                .header-table td { padding: 1px 6px 1px 0; font-size: 8.5pt; }
                table.ledger { border-collapse: collapse; width: 100%; margin-top: 8px; }
                table.ledger th, table.ledger td { border: 0.2mm solid #999; padding: 3px 4px; font-size: 8pt; }
                table.ledger th { background-color: #F0E0FC; text-align: left; }
                .num { text-align: right; }
                .center { text-align: center; }
            </style>
            <h1>{$title}</h1>
            {$header}
            <table class="ledger">
                <thead>
                    <tr>
                        <th>{$this->col('col_date')}</th>
                        <th>{$this->col('col_txn_type')}</th>
                        <th>{$this->col('col_issuer')}</th>
                        <th>{$this->col('col_receiver')}</th>
                        <th class="num">{$this->col('col_in')}</th>
                        <th class="num">{$this->col('col_out')}</th>
                        <th class="num">{$this->col('col_balance')}</th>
                        <th class="center">{$this->col('col_signature')}</th>
                        <th>{$this->col('col_remark')}</th>
                    </tr>
                </thead>
                <tbody>
                    {$bodyRows}
                </tbody>
            </table>
            HTML;
    }

    private function headerHtml(Item $item): string
    {
        $fields = [
            'header_category' => $item->category?->name_th,
            'header_item' => $item->name_th.' ('.$item->item_code.')',
            'header_brand' => $item->brand,
            'header_grade' => $item->grade,
            'header_package_size' => $item->package_size !== null
                ? $item->package_size.' '.$item->packageUnit?->code
                : null,
            'header_sub_unit' => $item->subUnit?->code,
            'header_unit' => $item->baseUnit?->code,
        ];

        $cells = collect($fields)
            ->map(fn (?string $value, string $label) => '<td><b>'.e(__('ledger.'.$label)).':</b> '.e($value ?? '—').'</td>')
            ->implode('');

        return '<table class="header-table"><tr>'.$cells.'</tr></table>';
    }

    private function col(string $key): string
    {
        return e(__('ledger.'.$key));
    }

    private function rowHtml(LedgerRow $row, string $unitCode): string
    {
        $date = e($row->txnDate->format('d/m/Y'));
        $type = e(__('ledger.txn_'.strtolower($row->txnType)));
        $issuer = e($row->issuerName ?? '—');
        $receiver = e($row->receiverName ?? '—');
        $in = $row->qtyIn !== null ? e("{$row->qtyIn} {$unitCode}") : '—';
        $out = $row->qtyOut !== null ? e("{$row->qtyOut} {$unitCode}") : '—';
        $balance = e("{$row->balance} {$unitCode}");
        $signed = $row->signed ? e(__('ledger.signed_yes')) : '';
        $remark = e($row->remark ?? '—');

        return <<<HTML
            <tr>
                <td>{$date}</td>
                <td>{$type}</td>
                <td>{$issuer}</td>
                <td>{$receiver}</td>
                <td class="num">{$in}</td>
                <td class="num">{$out}</td>
                <td class="num">{$balance}</td>
                <td class="center">{$signed}</td>
                <td>{$remark}</td>
            </tr>
            HTML;
    }
}
