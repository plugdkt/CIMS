<?php

declare(strict_types=1);

namespace App\Domain\Labeling\Services;

use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\StockLedger;
use App\Domain\Reporting\Services\MpdfFactory;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Mpdf\Output\Destination;

/**
 * FR-RC-04: container labels at 40×25mm and 50×30mm, several per A4 page.
 *
 * Uses a QR code, not a Code 128 barcode — user-approved 2026-09-17: the lab doesn't
 * yet own a 2D scanner but is buying one, and a QR reads fine at close range even
 * before then (any modern phone camera also decodes it). `qr_size` per label size is
 * hand-picked to leave enough room for the bigger label text below without the QR
 * getting cramped against it.
 */
final class ContainerLabelPdfService
{
    /** @var array<string, array{width: int, height: int, columns: int, qr_size: int}> */
    private const SIZES = [
        '40x25' => ['width' => 40, 'height' => 25, 'columns' => 5, 'qr_size' => 12],
        '50x30' => ['width' => 50, 'height' => 30, 'columns' => 4, 'qr_size' => 17],
    ];

    public function __construct(private readonly QrCodeGenerator $qrCodeGenerator)
    {
    }

    /** @return list<string> valid `$size` values for {@see render()} */
    public static function availableSizes(): array
    {
        return array_keys(self::SIZES);
    }

    /**
     * containers.* has no `goods_receipt_id` column (spec's §5.2 DDL never added one) —
     * the RECEIVE ledger row each container got at confirm (T-021/T-022) is the only
     * recorded link back to the GRN that created it (`ref_type`/`ref_id`), so that's
     * what this traces through.
     *
     * @return Collection<int, Container>
     */
    public function containersFor(GoodsReceipt $goodsReceipt): Collection
    {
        $containerIds = StockLedger::where('ref_type', 'GRN')
            ->where('ref_id', $goodsReceipt->id)
            ->whereNotNull('container_id')
            ->distinct()
            ->pluck('container_id');

        return Container::with('item')->whereIn('id', $containerIds)->orderBy('id')->get();
    }

    /** @param Collection<int, Container> $containers */
    public function render(Collection $containers, string $size): string
    {
        if (! isset(self::SIZES[$size])) {
            throw new InvalidArgumentException("ขนาดฉลากไม่ถูกต้อง: {$size}");
        }

        $spec = self::SIZES[$size];

        $mpdf = MpdfFactory::make([
            'format' => 'A4',
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_top' => 5,
            'margin_bottom' => 5,
            'margin_header' => 0,
            'margin_footer' => 0,
            'default_font' => 'garuda',
        ]);

        $mpdf->WriteHTML($this->buildHtml($containers, $spec));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param  Collection<int, Container>  $containers
     * @param  array{width: int, height: int, columns: int, qr_size: int}  $spec
     */
    private function buildHtml(Collection $containers, array $spec): string
    {
        $html = '<style>
            table { border-collapse: collapse; width: 100%; table-layout: fixed; }
            td { width: '.$spec['width'].'mm; height: '.$spec['height'].'mm; border: 0.2mm dashed #999;
                 text-align: center; vertical-align: middle; padding: 1mm; overflow: hidden; }
            .item-name { font-size: 9pt; font-weight: bold; }
            .qr-svg { width: '.$spec['qr_size'].'mm; height: '.$spec['qr_size'].'mm; }
            .code-text { font-size: 8pt; }
            .lot-expiry { font-size: 7pt; color: #444; }
        </style><table><tbody>';

        foreach ($containers->chunk($spec['columns']) as $row) {
            $html .= '<tr>';
            foreach ($row as $container) {
                $html .= $this->labelCellHtml($container, $spec['qr_size']);
            }
            for ($pad = $row->count(); $pad < $spec['columns']; $pad++) {
                $html .= '<td></td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function labelCellHtml(Container $container, int $qrSizeMm): string
    {
        $itemName = e($container->item === null ? '' : $container->item->name_th);
        // The wrapping .qr-svg div's CSS width/height is the real sizing mechanism, but
        // the generated SVG's own intrinsic pixel size is given a matching estimate too
        // (96 CSS px/inch, 25.4mm/inch) as a safeguard in case mPDF doesn't scale an
        // embedded SVG down from its native size to fit a smaller container.
        $qrSvg = $this->qrCodeGenerator->svg($container->barcode, (int) round($qrSizeMm / 25.4 * 96));
        $code = e($container->barcode);

        $lotExpiry = trim(implode(' · ', array_filter([
            $container->lot_no !== null ? 'Lot '.e($container->lot_no) : null,
            $container->expiry_date !== null ? 'EXP '.$container->expiry_date->format('d/m/Y') : null,
        ])));

        return <<<HTML
            <td>
                <div class="item-name">{$itemName}</div>
                <div class="qr-svg">{$qrSvg}</div>
                <div class="code-text">{$code}</div>
                <div class="lot-expiry">{$lotExpiry}</div>
            </td>
            HTML;
    }
}
