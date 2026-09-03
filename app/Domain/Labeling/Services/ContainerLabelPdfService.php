<?php

declare(strict_types=1);

namespace App\Domain\Labeling\Services;

use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** FR-RC-04: barcode labels at 40×25mm and 50×30mm, several per A4 page. */
final class ContainerLabelPdfService
{
    /** @var array<string, array{width: int, height: int, columns: int}> */
    private const SIZES = [
        '40x25' => ['width' => 40, 'height' => 25, 'columns' => 5],
        '50x30' => ['width' => 50, 'height' => 30, 'columns' => 4],
    ];

    public function __construct(private readonly BarcodeGenerator $barcodeGenerator)
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

        $mpdf = new Mpdf([
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
     * @param  array{width: int, height: int, columns: int}  $spec
     */
    private function buildHtml(Collection $containers, array $spec): string
    {
        $html = '<style>
            table { border-collapse: collapse; width: 100%; table-layout: fixed; }
            td { width: '.$spec['width'].'mm; height: '.$spec['height'].'mm; border: 0.2mm dashed #999;
                 text-align: center; vertical-align: middle; padding: 1mm; overflow: hidden; }
            .item-name { font-size: 7pt; font-weight: bold; }
            .barcode-svg { width: '.($spec['width'] - 4).'mm; }
            .code-text { font-size: 6pt; }
            .lot-expiry { font-size: 6pt; color: #444; }
        </style><table><tbody>';

        foreach ($containers->chunk($spec['columns']) as $row) {
            $html .= '<tr>';
            foreach ($row as $container) {
                $html .= $this->labelCellHtml($container);
            }
            for ($pad = $row->count(); $pad < $spec['columns']; $pad++) {
                $html .= '<td></td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function labelCellHtml(Container $container): string
    {
        $itemName = e($container->item === null ? '' : $container->item->name_th);
        $barcodeSvg = $this->barcodeGenerator->svg($container->barcode);
        $code = e($container->barcode);

        $lotExpiry = trim(implode(' · ', array_filter([
            $container->lot_no !== null ? 'Lot '.e($container->lot_no) : null,
            $container->expiry_date !== null ? 'EXP '.$container->expiry_date->format('d/m/Y') : null,
        ])));

        return <<<HTML
            <td>
                <div class="item-name">{$itemName}</div>
                <div class="barcode-svg">{$barcodeSvg}</div>
                <div class="code-text">{$code}</div>
                <div class="lot-expiry">{$lotExpiry}</div>
            </td>
            HTML;
    }
}
