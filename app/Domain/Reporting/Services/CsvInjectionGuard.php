<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

/**
 * SEC-IN-10: every CSV/Excel export must prefix a value with `'` when it starts with
 * `= + - @` — otherwise a spreadsheet program (Excel, LibreOffice, Google Sheets) may
 * interpret it as a formula on open, letting a value a user typed once (e.g. a requisition
 * remark or a receiver name) execute arbitrary formulas/macros for whoever later opens the
 * export. Applied to every free-text cell in every export this app produces.
 */
final class CsvInjectionGuard
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@'];

    public static function sanitize(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }
}
