<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the university warehouse's "สรุปการรับเข้า-เบิกจ่าย-คงเหลือของวัสดุ" report (a Crystal
 * Reports print-out) by text position, not by reading the words.
 *
 * Two facts about that file drive this design (verified against a real 36-page sample):
 *  - The Thai in its text layer is garbled (the font's ToUnicode map is wrong — "เบิกจ่าย"
 *    comes out as "เบบกจจาย"), so item names cannot be trusted; the "AS" item code and the
 *    numbers are intact, and the name comes from our own catalog.
 *  - Empty cells are simply absent from the text, so a number's column can only be told from
 *    where it sits on the page, not from its order among the other numbers.
 *
 * Columns are assigned by each number's estimated right edge (the report right-aligns every
 * numeric column). Every parsed row is cross-checked: opening + received − issued must equal
 * the closing balance, otherwise the row is flagged for the reviewing manager.
 */
final class ImsStockReportParser
{
    private const SCALE = 6;

    private const DIGIT_WIDTH = 4.0;

    private const PUNCT_WIDTH = 1.9;

    /** Width of the leading space the report prints before every number run. */
    private const LEADING_SPACE = 2.4;

    /** Upper bound of each numeric column's right edge, in PDF points (midway between neighbours). */
    private const COLUMN_LIMITS = [
        'open_qty' => 309.0, 'open_price' => 357.0, 'open_total' => 405.0,
        'in_qty' => 443.0, 'in_price' => 489.0, 'in_total' => 529.0,
        'out_qty' => 570.0, 'out_price' => 614.0, 'out_total' => 652.0,
        'bal_qty' => 690.0, 'bal_price' => 736.0, 'bal_total' => 9999.0,
    ];

    /**
     * @return array{
     *     period_from: ?string,
     *     period_to: ?string,
     *     custodian: ?string,
     *     rows: list<array<string, mixed>>
     * }
     *
     * @throws Throwable when the file is not a readable PDF
     */
    public function parse(string $path): array
    {
        $pdf = (new Parser())->parseFile($path);

        $rows = [];
        $custodian = null;
        $dates = [];

        foreach ($pdf->getPages() as $pageNo => $page) {
            /** @var list<array{0: array<int, float|int|string>, 1: string}> $runs */
            $runs = $page->getDataTm();

            $anchors = [];
            $numbers = [];
            foreach ($runs as $run) {
                $x = (float) $run[0][4];
                $y = (float) $run[0][5];
                $text = trim($run[1]);
                if ($text === '') {
                    continue;
                }

                if (preg_match('/^(E\d{4})\s*:/', $text, $m) === 1 && $custodian === null) {
                    $custodian = $m[1];
                }
                if (count($dates) < 2 && preg_match('/(\d{2}\/\d{2}\/\d{4})/', $text, $m) === 1 && str_contains($text, ':')) {
                    $dates[] = $m[1];
                }
                if (preg_match('/^AS\d{6}$/', $text) === 1) {
                    $anchors[] = ['code' => $text, 'y' => $y];
                } elseif (preg_match('/^-?\d[\d,]*(\.\d+)?$/', $text) === 1) {
                    $numbers[] = ['x' => $x, 'y' => $y, 'text' => $text];
                }
            }

            foreach ($anchors as $anchor) {
                $row = ['page' => $pageNo + 1, 'code' => $anchor['code'], 'row_no' => null];
                foreach ($numbers as $number) {
                    if (abs($number['y'] - $anchor['y']) > 5.0) {
                        continue;
                    }
                    if ($number['x'] < 45.0) {
                        $row['row_no'] = (int) str_replace(',', '', $number['text']);

                        continue;
                    }
                    $column = $this->columnFor($number['x'] + self::LEADING_SPACE + $this->textWidth($number['text']));
                    $row[$column] = str_replace(',', '', $number['text']);
                }
                $rows[] = $this->withChecks($row);
            }
        }

        return [
            'period_from' => $dates[0] ?? null,
            'period_to' => $dates[1] ?? null,
            'custodian' => $custodian,
            'rows' => $rows,
        ];
    }

    private function textWidth(string $text): float
    {
        $digits = preg_match_all('/\d/', $text);
        $other = strlen($text) - (int) $digits;

        return ((int) $digits * self::DIGIT_WIDTH) + ($other * self::PUNCT_WIDTH);
    }

    private function columnFor(float $rightEdge): string
    {
        foreach (self::COLUMN_LIMITS as $column => $limit) {
            if ($rightEdge <= $limit) {
                return $column;
            }
        }

        return 'bal_total';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withChecks(array $row): array
    {
        /** @var numeric-string $open */
        $open = $row['open_qty'] ?? '0';
        /** @var numeric-string $in */
        $in = $row['in_qty'] ?? '0';
        /** @var numeric-string $out */
        $out = $row['out_qty'] ?? '0';
        /** @var numeric-string $bal */
        $bal = $row['bal_qty'] ?? '0';

        $row['balanced'] = bccomp(bcsub(bcadd($open, $in, self::SCALE), $out, self::SCALE), $bal, self::SCALE) === 0;

        return $row;
    }
}
