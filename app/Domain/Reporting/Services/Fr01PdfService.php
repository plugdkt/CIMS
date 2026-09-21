<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Labeling\Services\QrCodeGenerator;
use App\Models\IssueTransaction;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Mpdf\Output\Destination;

/** FR-RQ-12: the printable F-01 requisition form, with a QR-verify corner (§7.2 GET /verify/{ulid}). */
final class Fr01PdfService
{
    public function __construct(private readonly QrCodeGenerator $qrCodeGenerator)
    {
    }

    public function render(Requisition $requisition): string
    {
        $requisition->load(['lab', 'requester', 'advisor', 'scientist', 'items.item', 'items.unit']);

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        $mpdf->SetTitle(__('requisitions.pdf_title').' '.$requisition->doc_no);
        $mpdf->WriteHTML($this->buildHtml($requisition));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function buildHtml(Requisition $requisition): string
    {
        $verifyUrl = URL::to("/verify/{$requisition->ulid}");
        $qrSvg = $this->qrCodeGenerator->svg($verifyUrl, 120);

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
                .signature-box { border: 0.2mm solid #999; padding: 6px; min-height: 20mm; }
                .signature-img { max-height: 18mm; }
                .qr-corner { position: fixed; bottom: 8mm; right: 15mm; text-align: center; font-size: 7pt; }
            </style>
            <h1>{$this->e(__('requisitions.pdf_title'))}</h1>
            <div class="doc-no">{$this->e($requisition->doc_no)}</div>

            {$this->requesterInfoHtml($requisition)}
            {$this->linesHtml($requisition)}
            {$this->approvalsHtml($requisition)}
            {$this->receiverHtml($requisition)}

            <div class="qr-corner">
                {$qrSvg}
                <div>{$this->e(__('requisitions.pdf_verify_hint'))}</div>
            </div>
            HTML;
    }

    /** @return array<string, string> */
    private function requesterInfoRows(Requisition $requisition): array
    {
        return [
            'field_doc_date' => $requisition->doc_date->format('d/m/Y'),
            'field_lab' => $requisition->lab()->firstOrFail()->name_th,
            'field_requester_name' => $requisition->requester()->firstOrFail()->full_name,
            'field_requester_status' => (string) __('requisitions.person_type_'.strtolower($requisition->requester_status)),
            'field_program' => (string) ($requisition->program ?? '—'),
            'field_faculty' => (string) ($requisition->faculty ?? '—'),
            'field_purpose_type' => (string) __('requisitions.purpose_type_'.strtolower($requisition->purpose_type)),
            'field_purpose_detail' => (string) ($requisition->purpose_detail ?? '—'),
        ];
    }

    private function requesterInfoHtml(Requisition $requisition): string
    {
        $cells = collect($this->requesterInfoRows($requisition))
            ->map(fn (string $value, string $label) => '<td><b>'.$this->e(__('requisitions.'.$label)).':</b> '.$this->e($value).'</td>')
            ->chunk(2)
            ->map(fn ($pair) => '<tr>'.$pair->implode('').'</tr>')
            ->implode('');

        return "<table class=\"info\">{$cells}</table>";
    }

    private function linesHtml(Requisition $requisition): string
    {
        $rows = $requisition->items->map(function (RequisitionItem $line) {
            $lineItem = $line->item()->firstOrFail();
            $item = $this->e($lineItem->name_th);

            $subParts = [];
            if ($lineItem->item_code) {
                $subParts[] = $lineItem->item_code;
            }
            if ($lineItem->grade) {
                $subParts[] = __('items.field_grade').': '.$lineItem->grade;
            }
            if ($lineItem->physical_state) {
                $subParts[] = __('items.state_'.$lineItem->physical_state);
            }
            $itemSub = ! empty($subParts)
                ? '<div style="font-size: 7.5pt; color: #555; margin-top: 2px;">'.$this->e(implode(' | ', $subParts)).'</div>'
                : '';

            $qtyRequested = $this->e($this->trimQty($line->qty_requested).' '.$line->unit?->code);
            $qtyIssued = $this->e($this->trimQty($line->qty_issued_base)).' ('.$this->e($lineItem->baseUnit?->code).')';
            $reference = $this->e($line->reference_doc ?? '—');

            return <<<HTML
                <tr>
                    <td>{$line->line_no}</td>
                    <td>{$item}{$itemSub}</td>
                    <td class="num">{$qtyRequested}</td>
                    <td class="num">{$qtyIssued}</td>
                    <td>{$reference}</td>
                </tr>
                HTML;
        })->implode('');

        $colHeaders = [
            '#',
            $this->e(__('requisitions.field_item')),
            $this->e(__('requisitions.field_qty_requested')),
            $this->e(__('requisitions.pdf_qty_issued')),
            $this->e(__('requisitions.field_reference_doc')),
        ];
        $head = '<tr>'.collect($colHeaders)->map(fn ($h) => "<th>{$h}</th>")->implode('').'</tr>';

        return "<div class=\"section-title\">{$this->e(__('requisitions.lines_title'))}</div>
            <table class=\"lines\"><thead>{$head}</thead><tbody>{$rows}</tbody></table>";
    }

    private function approvalsHtml(Requisition $requisition): string
    {
        $advisor = $requisition->advisor_id === null
            ? $this->e(__('requisitions.pdf_not_applicable'))
            : $this->e($requisition->advisor?->full_name).' — '.($requisition->advisor_signed_at !== null
                ? $this->e(__('requisitions.pdf_signed_at', ['date' => $requisition->advisor_signed_at->format('d/m/Y H:i')]))
                : $this->e(__('requisitions.pdf_pending')));

        $scientist = $requisition->scientist_id === null
            ? $this->e(__('requisitions.pdf_pending'))
            : $this->e($requisition->scientist?->full_name).' — '
                .$this->e(__('requisitions.scientist_'.strtolower((string) $requisition->scientist_decision).'_decision'))
                .' ('.$this->e(optional($requisition->scientist_signed_at)->format('d/m/Y H:i')).')'
                .($requisition->scientist_decision === 'REJECT' ? ' — '.$this->e($requisition->reject_reason ?? '') : '');

        return <<<HTML
            <div class="section-title">{$this->e(__('requisitions.pdf_advisor_decision'))}</div>
            <div>{$advisor}</div>
            <div class="section-title">{$this->e(__('requisitions.pdf_scientist_decision'))}</div>
            <div>{$scientist}</div>
            HTML;
    }

    private function receiverHtml(Requisition $requisition): string
    {
        $lastSigned = IssueTransaction::whereHas(
            'requisitionItem',
            fn ($q) => $q->where('requisition_id', $requisition->id)
        )->whereNotNull('signature_hash')->latest('id')->first();

        $receiverName = $this->e($requisition->requester?->full_name);

        if ($lastSigned === null) {
            $proof = $this->e(__('requisitions.pdf_not_yet_issued'));
        } elseif ($lastSigned->signature_image_path !== null) {
            $binary = Storage::disk('signatures')->get($lastSigned->signature_image_path);
            $src = 'data:image/png;base64,'.base64_encode((string) $binary);
            $proof = "<img class=\"signature-img\" src=\"{$src}\" alt=\"signature\">";
        } else {
            $proof = $this->e(__('requisitions.pdf_otp_verified'));
        }

        return <<<HTML
            <div class="section-title">{$this->e(__('requisitions.pdf_receiver'))}: {$receiverName}</div>
            <div class="signature-box">{$proof}</div>
            HTML;
    }

    private function e(?string $value): string
    {
        return e($value ?? '');
    }

    /** Display-only: trims trailing zeros (and a bare decimal point) off a
     *  DECIMAL(18,6)-backed quantity — the stored/ledger value is never touched. */
    private function trimQty(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
