<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Exports\ItemIssueHistoryExport;
use App\Domain\Reporting\Exports\ItemReceivingHistoryExport;
use App\Domain\Reporting\Exports\ItemReturnHistoryExport;
use App\Models\IssueTransaction;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\StockLedger;
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
    /**
     * User-requested 2026-09-22: "ประวัติการรับเข้าเป็นตารางด้านบน แล้วต่อด้วยประวัติการเบิก และท้ายตาราง
     * บอกจำนวนคงเหลือไว้ด้วย" — receiving history first (it's the earlier event in the item's
     * life), dispensing history below it, and the current balance at the very end of the
     * document, after every table.
     *
     * A return history section was added the same day, in between dispensing and the
     * balance — a real return (received 250, issued 100 across two dispensings, 50
     * returned, balance correctly 200) made the report look broken without it: "เบิก 100
     * คงเหลือ 200" looks like it exceeds the 250 received, until the return that explains it
     * is visible. Nothing about the issued total or the balance was ever wrong; the report
     * was just missing this whole category of movement (see {@see ItemReturnHistoryExport}).
     */
    public function render(
        ItemIssueHistoryExport $issueExport,
        ItemReceivingHistoryExport $receivingExport,
        ItemReturnHistoryExport $returnExport,
    ): string {
        $item = $issueExport->itemFor();
        $issueRows = $issueExport->results();
        $receivingRows = $receivingExport->results();
        $returnRows = $returnExport->results();

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'orientation' => 'L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
        ]);

        $mpdf->SetTitle((string) __('reports.item_issue_history_title').' — '.$item->name_th);
        $mpdf->WriteHTML($this->buildHtml($item, $issueRows, $receivingRows, $returnRows, $issueExport, $returnExport));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param  Collection<int, IssueTransaction>  $issueRows
     * @param  Collection<int, StockLedger>  $receivingRows
     * @param  Collection<int, StockLedger>  $returnRows
     */
    private function buildHtml(
        Item $item,
        Collection $issueRows,
        Collection $receivingRows,
        Collection $returnRows,
        ItemIssueHistoryExport $export,
        ItemReturnHistoryExport $returnExport,
    ): string {
        $title = e(__('reports.item_issue_history_title'));

        return <<<HTML
            <style>
                body { font-size: 9pt; }
                h1 { font-size: 13pt; margin-bottom: 4px; }
                h2 { font-size: 11pt; margin-top: 10px; margin-bottom: 4px; }
                .header-table td { padding: 1px 6px 1px 0; font-size: 8.5pt; }
                table.report { border-collapse: collapse; width: 100%; margin-top: 8px; }
                table.report th, table.report td { border: 0.2mm solid #999; padding: 3px 4px; font-size: 8pt; }
                table.report th { background-color: #F0E0FC; text-align: left; }
                .num { text-align: right; }
                .balance-footer { margin-top: 10px; font-size: 10pt; text-align: right; }
            </style>
            <h1>{$title}</h1>
            {$this->itemInfoHtml($item)}
            {$this->receivingHtml($receivingRows, $item)}
            {$this->issueHtml($issueRows, $item)}
            {$this->returnHtml($returnRows, $item)}
            {$this->balanceFooterHtml($item, $export, $returnExport)}
            HTML;
    }

    /** Same field set as Fr03PdfService's own item info block, for a consistent look. */
    private function itemInfoHtml(Item $item): string
    {
        $fields = [
            'header_category' => $item->category?->name_th,
            'header_item' => $item->name_th.' ('.$item->item_code.')',
            'header_brand' => $item->brand,
            'header_grade' => $item->grade,
        ];

        $cells = collect($fields)
            ->map(fn (?string $value, string $label) => '<td><b>'.e(__('ledger.'.$label)).':</b> '.e($value ?? '—').'</td>')
            ->implode('');

        return '<table class="header-table"><tr>'.$cells.'</tr></table>';
    }

    /** @param  Collection<int, StockLedger>  $rows */
    private function receivingHtml(Collection $rows, Item $item): string
    {
        $title = e(__('reports.item_receiving_history_title'));
        $unit = $item->baseUnit?->code;

        $bodyRows = $rows->isEmpty()
            ? '<tr><td colspan="4" style="text-align:center;padding:8px;">'.e(__('reports.item_receiving_history_empty')).'</td></tr>'
            : $rows->map(fn (StockLedger $row) => $this->receivingRowHtml($row, $unit))->implode('');

        return <<<HTML
            <h2>{$title}</h2>
            <table class="report">
                <thead>
                    <tr>
                        <th>{$this->col('col_date')}</th>
                        <th>{$this->col('col_ims_doc_no')}</th>
                        <th>{$this->col('col_received_by')}</th>
                        <th class="num">{$this->col('col_qty_received')}</th>
                    </tr>
                </thead>
                <tbody>
                    {$bodyRows}
                </tbody>
            </table>
            HTML;
    }

    /** @param  Collection<int, IssueTransaction>  $rows */
    private function issueHtml(Collection $rows, Item $item): string
    {
        $title = e(__('reports.item_issue_history_title'));
        $unit = $item->baseUnit?->code;

        $bodyRows = $rows->isEmpty()
            ? '<tr><td colspan="5" style="text-align:center;padding:8px;">'.e(__('reports.item_issue_history_empty')).'</td></tr>'
            : $rows->map(fn (IssueTransaction $row) => $this->issueRowHtml($row, $unit))->implode('');

        return <<<HTML
            <h2>{$title}</h2>
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

    /** @param  Collection<int, StockLedger>  $rows */
    private function returnHtml(Collection $rows, Item $item): string
    {
        $title = e(__('reports.item_return_history_title'));
        $unit = $item->baseUnit?->code;

        $bodyRows = $rows->isEmpty()
            ? '<tr><td colspan="4" style="text-align:center;padding:8px;">'.e(__('reports.item_return_history_empty')).'</td></tr>'
            : $rows->map(fn (StockLedger $row) => $this->returnRowHtml($row, $unit))->implode('');

        return <<<HTML
            <h2>{$title}</h2>
            <table class="report">
                <thead>
                    <tr>
                        <th>{$this->col('col_date')}</th>
                        <th>{$this->col('col_doc_no')}</th>
                        <th>{$this->col('col_requester')}</th>
                        <th class="num">{$this->col('col_qty_returned')}</th>
                    </tr>
                </thead>
                <tbody>
                    {$bodyRows}
                </tbody>
            </table>
            HTML;
    }

    private function balanceFooterHtml(Item $item, ItemIssueHistoryExport $export, ItemReturnHistoryExport $returnExport): string
    {
        $unit = $item->baseUnit?->code;
        $totalIssued = e(ItemIssueHistoryExport::trimQty($export->totalIssued()).' '.$unit);
        $totalReturned = e(ItemIssueHistoryExport::trimQty($returnExport->totalReturned()).' '.$unit);
        $balance = e(ItemIssueHistoryExport::trimQty($export->remainingBalance()).' '.$unit);

        return '<p class="balance-footer">'
            .'<b>'.e(__('reports.summary_total_issued')).':</b> '.$totalIssued.'&nbsp;&nbsp;&nbsp;'
            .'<b>'.e(__('reports.summary_total_returned')).':</b> '.$totalReturned.'&nbsp;&nbsp;&nbsp;'
            .'<b>'.e(__('reports.summary_balance')).':</b> '.$balance
            .'</p>';
    }

    private function col(string $key): string
    {
        return e(__('reports.'.$key));
    }

    private function issueRowHtml(IssueTransaction $row, ?string $unit): string
    {
        $requisitionItem = $row->requisitionItem()->firstOrFail();
        $requisition = $requisitionItem->requisition()->firstOrFail();
        $requester = $requisition->requester()->firstOrFail();

        $date = e($row->issued_at->format('d/m/Y'));
        $docNo = e($requisition->doc_no);
        $requesterName = e($requester->full_name);
        $faculty = e((string) ($requisition->faculty ?? '—'));
        $qty = e(trim(ItemIssueHistoryExport::trimQty((string) $row->qty_issued_base).' '.$unit));

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

    private function receivingRowHtml(StockLedger $row, ?string $unit): string
    {
        $creator = $row->creator()->first();
        $creatorName = $creator === null ? '—' : $creator->full_name;

        $date = e($row->txn_date->format('d/m/Y'));
        $docNo = e((string) ($row->remark ?? '—'));
        $receivedBy = e($creatorName);
        $qty = e(trim(ItemIssueHistoryExport::trimQty((string) $row->qty_in_base).' '.$unit));

        return <<<HTML
            <tr>
                <td>{$date}</td>
                <td>{$docNo}</td>
                <td>{$receivedBy}</td>
                <td class="num">{$qty}</td>
            </tr>
            HTML;
    }

    private function returnRowHtml(StockLedger $row, ?string $unit): string
    {
        $requisition = $row->ref_type === 'REQUISITION' && $row->ref_id !== null
            ? Requisition::find($row->ref_id)
            : null;
        $requester = $requisition === null ? null : $requisition->requester()->first();
        $requesterName = $requester === null ? '—' : $requester->full_name;

        $date = e($row->txn_date->format('d/m/Y'));
        $docNo = e((string) ($row->ref_doc_no ?? '—'));
        $qty = e(trim(ItemIssueHistoryExport::trimQty((string) $row->qty_in_base).' '.$unit));

        return <<<HTML
            <tr>
                <td>{$date}</td>
                <td>{$docNo}</td>
                <td>{$requesterName}</td>
                <td class="num">{$qty}</td>
            </tr>
            HTML;
    }
}
