<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Exports\ItemIssueHistoryExport;
use App\Models\IssueTransaction;
use App\Models\Item;
use Illuminate\Support\Collection;
use Mpdf\Output\Destination;

/**
 * User-requested 2026-09-22: "ให้อ้างอิงตามแบบฟอร์ม F-03" — printed in the same document
 * style as {@see Fr03PdfService} (title, item info header block, bordered table), not a
 * merge with F-03's own data. F-03 is the full multi-transaction-type ledger register;
 * this keeps {@see ItemIssueHistoryExport}'s own columns (who dispensed, when, how much) —
 * only the visual form matches.
 *
 * Row count here is naturally bounded (one item's dispensing history, not a 100,000-row
 * ledger), so this uses mPDF's ordinary WriteHTML() table like
 * {@see ControlledSubstancesPdfService} — NFR-02's Cell()-based approach in Fr03PdfService
 * only pays for itself at a row count this report never reaches.
 */
final class ItemIssueHistoryPdfService
{
    public function render(ItemIssueHistoryExport $export): string
    {
        $item = $export->itemFor();
        $rows = $export->results();

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->SetTitle((string) __('reports.item_issue_history_title').' — '.$item->name_th);
        $mpdf->WriteHTML($this->buildHtml($item, $rows, $export));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @param  Collection<int, IssueTransaction>  $rows */
    private function buildHtml(Item $item, Collection $rows, ItemIssueHistoryExport $export): string
    {
        $title = e(__('reports.item_issue_history_title'));
        $header = $this->headerHtml($item, $export);

        $bodyRows = $rows->isEmpty()
            ? '<tr><td colspan="5" style="text-align:center;padding:8px;">'.e(__('reports.item_issue_history_empty')).'</td></tr>'
            : $rows->map(fn (IssueTransaction $row) => $this->rowHtml($row))->implode('');

        return <<<HTML
            <style>
                body { font-size: 9pt; }
                h1 { font-size: 13pt; margin-bottom: 4px; }
                .header-table td { padding: 1px 6px 1px 0; font-size: 8.5pt; }
                table.report { border-collapse: collapse; width: 100%; margin-top: 8px; }
                table.report th, table.report td { border: 0.2mm solid #999; padding: 3px 4px; font-size: 8pt; }
                table.report th { background-color: #F0E0FC; text-align: left; }
                .num { text-align: right; }
            </style>
            <h1>{$title}</h1>
            {$header}
            <table class="report">
                <thead>
                    <tr>
                        <th>{$this->col('col_date')}</th>
                        <th>{$this->col('col_doc_no')}</th>
                        <th>{$this->col('col_requester')}</th>
                        <th>{$this->col('col_faculty')}</th>
                        <th class="num">{$this->col('col_qty_issued')}</th>
                    </tr>
                </thead>
                <tbody>
                    {$bodyRows}
                </tbody>
            </table>
            HTML;
    }

    /** Same field set as Fr03PdfService's own item info block, for a consistent look. */
    private function headerHtml(Item $item, ItemIssueHistoryExport $export): string
    {
        $unit = $item->baseUnit?->code;

        $fields = [
            'header_category' => $item->category?->name_th,
            'header_item' => $item->name_th.' ('.$item->item_code.')',
            'header_brand' => $item->brand,
            'header_grade' => $item->grade,
        ];

        $cells = collect($fields)
            ->map(fn (?string $value, string $label) => '<td><b>'.e(__('ledger.'.$label)).':</b> '.e($value ?? '—').'</td>')
            ->implode('');

        $totalIssued = e($export->totalIssued().' '.$unit);
        $balance = e($export->remainingBalance().' '.$unit);

        return '<table class="header-table"><tr>'.$cells.'</tr><tr>'
            .'<td><b>'.e(__('reports.summary_total_issued')).':</b> '.$totalIssued.'</td>'
            .'<td><b>'.e(__('reports.summary_balance')).':</b> '.$balance.'</td>'
            .'</tr></table>';
    }

    private function col(string $key): string
    {
        return e(__('reports.'.$key));
    }

    private function rowHtml(IssueTransaction $row): string
    {
        $requisitionItem = $row->requisitionItem()->firstOrFail();
        $requisition = $requisitionItem->requisition()->firstOrFail();
        $requester = $requisition->requester()->firstOrFail();

        $date = e($row->issued_at->format('d/m/Y'));
        $docNo = e($requisition->doc_no);
        $requesterName = e($requester->full_name);
        $faculty = e((string) ($requisition->faculty ?? '—'));
        $qty = e((string) $row->qty_issued_base);

        return <<<HTML
            <tr>
                <td>{$date}</td>
                <td>{$docNo}</td>
                <td>{$requesterName}</td>
                <td>{$faculty}</td>
                <td class="num">{$qty}</td>
            </tr>
            HTML;
    }
}
