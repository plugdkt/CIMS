<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\DocumentCounter;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * BR-09: {PREFIX}-{พ.ศ.}-{running 5 หลัก}, e.g. REQ-2569-00001.
 * Running resets every fiscal year (1 ต.ค.).
 */
final class DocumentNumberGenerator
{
    public function next(string $prefix, ?CarbonInterface $asOf = null): string
    {
        $fiscalYear = $this->fiscalYearBuddhist($asOf ?? now());

        return DB::transaction(function () use ($prefix, $fiscalYear) {
            // Guarantee the counter row exists before locking it — a plain
            // "no row yet" SELECT ... FOR UPDATE cannot lock anything, so two
            // concurrent first-calls for a brand new (prefix, fiscal_year) would
            // otherwise both try to insert and race on the unique constraint.
            DB::statement(
                'INSERT INTO document_counters (prefix, fiscal_year, last_number) VALUES (?, ?, 0)
                 ON DUPLICATE KEY UPDATE prefix = prefix',
                [$prefix, $fiscalYear]
            );

            /** @var DocumentCounter $counter */
            $counter = DocumentCounter::where('prefix', $prefix)
                ->where('fiscal_year', $fiscalYear)
                ->lockForUpdate()
                ->firstOrFail();

            $next = $counter->last_number + 1;
            $counter->update(['last_number' => $next]);

            return sprintf('%s-%s-%05d', $prefix, $fiscalYear, $next);
        });
    }

    private function fiscalYearBuddhist(CarbonInterface $date): string
    {
        $buddhistYear = $date->year + 543;

        return (string) ($date->month >= 10 ? $buddhistYear + 1 : $buddhistYear);
    }
}
