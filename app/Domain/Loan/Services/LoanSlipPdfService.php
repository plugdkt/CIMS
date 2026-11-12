<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Labeling\Services\QrCodeGenerator;
use App\Domain\Reporting\Services\MpdfFactory;
use App\Models\Loan;
use App\Models\LoanLine;
use Illuminate\Support\Facades\URL;
use Mpdf\Output\Destination;

/**
 * ITM-2610-051 step 3 (FR-LN-25): the printable loan slip, with a QR corner that leads to the public
 * `/verify/{ulid}` page (document number, date and status only, like the F-01 requisition form).
 *
 * The slip is an internal document opened behind LoanPolicy::printLoan, so it names the borrower. It never prints a
 * price (`replacement_value`) or anything from the borrower's profile beyond the display name. Like F-01 it has no
 * paper original to copy: the layout lists what the loan document itself carries. No signature boxes (the CEO
 * removed receiver signatures from the requisition flow on 2026-11-02; hand-out is one confirm button, FR-LN-20).
 */
final class LoanSlipPdfService
{
    public function __construct(private readonly QrCodeGenerator $qrCodeGenerator)
    {
    }

    public function render(Loan $loan): string
    {
        $loan->loadMissing(['lab', 'borrower', 'approver', 'advisor', 'lines.asset.item']);

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        $mpdf->SetTitle(__('loans.pdf.title').' '.$loan->doc_no);
        $mpdf->WriteHTML($this->buildHtml($loan));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function buildHtml(Loan $loan): string
    {
        $qrSvg = $this->qrCodeGenerator->svg(URL::to("/verify/{$loan->ulid}"), 120);

        return <<<HTML
            <style>
                body { font-size: 10pt; }
                h1 { font-size: 14pt; text-align: center; margin-bottom: 2px; }
                .doc-no { text-align: center; font-size: 9pt; color: #555; margin-bottom: 10px; }
                table.info td { padding: 2px 6px 2px 0; font-size: 9.5pt; vertical-align: top; }
                table.lines { border-collapse: collapse; width: 100%; margin-top: 6px; }
                table.lines th, table.lines td { border: 0.2mm solid #999; padding: 3px 5px; font-size: 9pt; }
                table.lines th { background-color: #F0E0FC; text-align: left; }
                .num { text-align: right; }
                .section-title { font-weight: bold; margin-top: 12px; margin-bottom: 3px; font-size: 10pt; }
                .qr-corner { position: fixed; bottom: 8mm; right: 15mm; text-align: center; font-size: 7pt; }
            </style>
            <h1>{$this->e(__('loans.pdf.title'))}</h1>
            <div class="doc-no">{$this->e($loan->doc_no)}</div>

            {$this->infoHtml($loan)}
            {$this->linesHtml($loan)}

            <div class="qr-corner">
                {$qrSvg}
                <div>{$this->e(__('loans.pdf.verify_hint'))}</div>
            </div>
            HTML;
    }

    private function infoHtml(Loan $loan): string
    {
        $rows = [
            'loans.field_borrower' => (string) $loan->borrower?->full_name,
            'loans.col_lab' => trim($loan->lab?->code.' '.$loan->lab?->name_th),
            'loans.field_purpose' => (string) __('loans.purpose_'.strtolower($loan->purpose_type)),
            'loans.col_status' => (string) __($loan->statusLabelKey()),
            'loans.field_loan_date' => $loan->loan_date->format('d/m/Y'),
            'loans.field_due_date' => $loan->due_date->format('d/m/Y'),
            'loans.pdf.handed_out_at' => $loan->handed_out_at?->format('d/m/Y H:i') ?? (string) __('loans.pdf.not_yet'),
            'loans.pdf.approved_by' => $loan->approver !== null
                ? (string) $loan->approver->full_name
                : ($loan->advisor_signed_at !== null ? (string) $loan->advisor?->full_name : (string) __('loans.pdf.not_yet')),
        ];

        $cells = collect($rows)
            ->map(fn (string $value, string $label) => '<td><b>'.$this->e(__($label)).':</b> '.$this->e($value).'</td>')
            ->chunk(2)
            ->map(fn ($pair) => '<tr>'.$pair->implode('').'</tr>')
            ->implode('');

        return "<table class=\"info\">{$cells}</table>";
    }

    private function linesHtml(Loan $loan): string
    {
        $rows = $loan->lines->map(function (LoanLine $line) {
            $item = $this->e($line->asset?->item?->name_th);
            $sub = $this->e(implode(' | ', array_filter([(string) $line->asset?->item?->item_code, (string) $line->asset?->asset_tag])));
            $condition = $line->condition_out === null
                ? '—'
                : $this->e(__('loans.condition_'.strtolower($line->condition_out)).($line->condition_out_note !== null ? ' ('.$line->condition_out_note.')' : ''));

            return <<<HTML
                <tr>
                    <td>{$line->line_no}</td>
                    <td>{$item}<div style="font-size: 7.5pt; color: #555; margin-top: 2px;">{$sub}</div></td>
                    <td class="num">{$this->e($this->trimQty($line->qty))}</td>
                    <td class="num">{$this->e($line->qty_approved === null ? '—' : $this->trimQty($line->qty_approved))}</td>
                    <td class="num">{$this->e($this->trimQty($line->qty_returned_ok))}</td>
                    <td>{$condition}</td>
                </tr>
                HTML;
        })->implode('');

        $head = '<tr>'.collect([
            '#',
            __('loans.col_item'),
            __('loans.col_requested'),
            __('loans.col_approved'),
            __('loans.col_returned'),
            __('loans.col_condition_out'),
        ])->map(fn ($h) => '<th>'.$this->e((string) $h).'</th>')->implode('').'</tr>';

        return "<div class=\"section-title\">{$this->e(__('loans.section_items'))}</div>
            <table class=\"lines\"><thead>{$head}</thead><tbody>{$rows}</tbody></table>";
    }

    private function e(?string $value): string
    {
        return e($value ?? '');
    }

    /** Display only: "3.000000" -> "3". */
    private function trimQty(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
