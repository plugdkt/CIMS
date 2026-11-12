<?php

declare(strict_types=1);

namespace App\Domain\Loan\Reports;

/**
 * ITM-2610-051 step 5: the result of one loan report — plain text cells, the same for the page and the CSV file.
 */
final class LoanReportTable
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     * @param  bool  $truncated  true when there were more rows than the limit asked for
     */
    public function __construct(
        public readonly string $key,
        public readonly array $headings,
        public readonly array $rows,
        public readonly bool $truncated = false,
    ) {
    }
}
