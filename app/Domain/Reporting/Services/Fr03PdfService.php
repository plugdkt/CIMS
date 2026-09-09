<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\DTO\LedgerRow;
use App\Domain\Inventory\Services\LedgerQueryService;
use App\Models\Item;
use App\Models\Unit;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * FR-LG-05: F-03 register as a printable PDF, respecting the same filters/display
 * unit as the screen.
 *
 * NFR-02: the table body is drawn with mPDF's raw Cell() API, not WriteHTML() — a
 * real 100,000-row measurement during T-052 found the HTML/CSS table path costs
 * ~1.9ms and ~90KB *per row* (mPDF's DOM/CSS layout engine, not the HTML string
 * size itself — chunking WriteHTML() calls alone doesn't help, since mPDF still
 * accumulates the same per-row layout state either way). At that rate 100,000 rows
 * would take ~190s and ~9GB, both far outside NFR-02's <15s target. Cell() skips
 * the HTML/CSS parser entirely, which is where nearly all of that per-row cost was
 * going — see CLAUDE.md for the measured numbers on both approaches.
 */
final class Fr03PdfService
{
    private const COL_WIDTHS_MM = [22, 22, 35, 35, 28, 28, 30, 20, 57];

    private const ROW_HEIGHT_MM = 5.0;

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
        $mpdf->WriteHTML($this->titleAndItemInfoHtml($item));

        $this->drawTableHeaderRow($mpdf);

        $unitCode = $displayUnit->code;

        if ($rows->isEmpty()) {
            $mpdf->SetFont('', '', 8);
            $mpdf->Cell(array_sum(self::COL_WIDTHS_MM), self::ROW_HEIGHT_MM, __('ledger.no_results'), 1, 1, 'C');
        } else {
            foreach ($rows as $row) {
                $this->drawDataRow($mpdf, $row, $unitCode);
            }
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function titleAndItemInfoHtml(Item $item): string
    {
        $title = e(__('ledger.title'));
        $header = $this->headerHtml($item);

        return <<<HTML
            <style>
                body { font-size: 9pt; }
                h1 { font-size: 13pt; margin-bottom: 4px; }
                .header-table td { padding: 1px 6px 1px 0; font-size: 8.5pt; }
            </style>
            <h1>{$title}</h1>
            {$header}
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

    /** @return list<string> */
    private function columnLabels(): array
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

    /** @return list<'L'|'R'|'C'> */
    private function columnAlignments(): array
    {
        return ['L', 'L', 'L', 'L', 'R', 'R', 'R', 'C', 'L'];
    }

    private function drawTableHeaderRow(Mpdf $mpdf): void
    {
        $mpdf->SetFont('', 'B', 8);
        $mpdf->SetFillColor(240, 224, 252);

        $labels = $this->columnLabels();
        foreach (self::COL_WIDTHS_MM as $i => $width) {
            $mpdf->Cell($width, self::ROW_HEIGHT_MM, $labels[$i], 1, 0, 'L', true);
        }
        $mpdf->Ln(self::ROW_HEIGHT_MM);
    }

    private function drawDataRow(Mpdf $mpdf, LedgerRow $row, string $unitCode): void
    {
        if ($mpdf->y + self::ROW_HEIGHT_MM > $mpdf->PageBreakTrigger) {
            $mpdf->AddPage();
            $this->drawTableHeaderRow($mpdf);
        }

        $in = $row->qtyIn !== null ? "{$row->qtyIn} {$unitCode}" : '—';
        $out = $row->qtyOut !== null ? "{$row->qtyOut} {$unitCode}" : '—';

        $values = [
            $row->txnDate->format('d/m/Y'),
            __('ledger.txn_'.strtolower($row->txnType)),
            $row->issuerName ?? '—',
            $row->receiverName ?? '—',
            $in,
            $out,
            "{$row->balance} {$unitCode}",
            $row->signed ? __('ledger.signed_yes') : '',
            $row->remark ?? '—',
        ];

        $mpdf->SetFont('', '', 8);
        $alignments = $this->columnAlignments();
        foreach (self::COL_WIDTHS_MM as $i => $width) {
            $mpdf->Cell($width, self::ROW_HEIGHT_MM, $this->truncateToFit($mpdf, $values[$i], $width), 1, 0, $alignments[$i]);
        }
        $mpdf->Ln(self::ROW_HEIGHT_MM);
    }

    /** Cell() doesn't wrap or clip text, so a too-long value is shortened with an ellipsis first. */
    private function truncateToFit(Mpdf $mpdf, string $text, float $widthMm): string
    {
        $innerWidthMm = $widthMm - 2;

        if ($mpdf->GetStringWidth($text) <= $innerWidthMm) {
            return $text;
        }

        $truncated = $text;
        while ($truncated !== '' && $mpdf->GetStringWidth($truncated.'…') > $innerWidthMm) {
            $truncated = mb_substr($truncated, 0, -1);
        }

        return $truncated === '' ? $text : $truncated.'…';
    }
}
