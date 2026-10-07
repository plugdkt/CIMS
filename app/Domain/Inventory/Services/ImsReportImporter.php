<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Models\ImsReceipt;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fills a DRAFT receipt from the university warehouse's stock-summary PDF. Only the closing
 * balance ("คงเหลือ") is imported — as pack counts and the price per pack, exactly as the
 * report prints them; the manager still reviews every line and confirms. Codes the catalog
 * does not know (office supplies, glassware, …) are skipped and listed, never created: the
 * report's Thai names are unreadable, so a new item could only be created with a wrong name.
 */
final class ImsReportImporter
{
    private const SCALE = 6;

    public function __construct(private readonly ImsStockReportParser $parser)
    {
    }

    /**
     * @return array<string, mixed> the summary that was stored on the receipt
     *
     * @throws ImsException when the file cannot be read as this report
     */
    public function import(ImsReceipt $receipt, string $pdfPath): array
    {
        try {
            $parsed = $this->parser->parse($pdfPath);
        } catch (Throwable) {
            throw new ImsException(__('ims.error.pdf_unreadable'));
        }

        if ($parsed['rows'] === []) {
            throw new ImsException(__('ims.error.pdf_no_rows'));
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $parsed['rows'];
        $items = Item::whereIn('item_code', array_unique(array_map(fn (array $r) => (string) $r['code'], $rows)))
            ->get()
            ->keyBy('item_code');

        $imported = 0;
        $noStock = 0;
        $unmatched = [];
        $unbalanced = [];

        DB::transaction(function () use ($receipt, $rows, $items, &$imported, &$noStock, &$unmatched, &$unbalanced) {
            $lineNo = (int) $receipt->lines()->max('line_no');

            foreach ($rows as $row) {
                $code = (string) $row['code'];
                /** @var numeric-string $balQty */
                $balQty = (string) ($row['bal_qty'] ?? '0');

                if (bccomp($balQty, '0', self::SCALE) <= 0) {
                    $noStock++;

                    continue;
                }

                if (! ($row['balanced'] ?? false)) {
                    $unbalanced[] = $code;
                }

                $item = $items->get($code);
                if ($item === null) {
                    $unmatched[] = [
                        'code' => $code,
                        'pack_qty' => $balQty,
                        'unit_price' => $row['bal_price'] ?? null,
                        'page' => $row['page'],
                    ];

                    continue;
                }

                $receipt->lines()->create([
                    'line_no' => ++$lineNo,
                    'item_id' => $item->id,
                    'item_code_raw' => $code,
                    'name_raw' => $item->name_th,
                    'pack_qty' => $balQty,
                    'unit_price' => $row['bal_price'] ?? null,
                ]);
                $imported++;
            }
        });

        $summary = [
            'custodian' => $parsed['custodian'],
            'period_from' => $parsed['period_from'],
            'period_to' => $parsed['period_to'],
            'rows_total' => count($rows),
            'imported' => $imported,
            'no_stock' => $noStock,
            'unmatched' => $unmatched,
            'unbalanced' => $unbalanced,
        ];

        $receipt->update(['import_summary' => $summary]);

        return $summary;
    }
}
