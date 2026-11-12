<?php

declare(strict_types=1);

namespace App\Domain\Loan\Reports;

/**
 * ITM-2610-051 step 5: the filters of a loan report, already validated (LoanReportRequest). A report reads only
 * the ones it uses (LoanReportService::FILTERS).
 */
final class LoanReportFilter
{
    public function __construct(
        public readonly ?string $from = null,
        public readonly ?string $to = null,
        public readonly ?string $month = null,
        public readonly ?string $borrower = null,
        public readonly ?string $item = null,
        public readonly ?int $minDays = null,
        public readonly ?string $kind = null,
    ) {
    }

    /**
     * What goes into the audit row of an export: dates and choices as they are, but for the two free-text search
     * boxes only WHETHER they were used (a typed name is personal data and does not belong in the audit log).
     *
     * @return array<string, string|int|bool|null>
     */
    public function forAudit(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'month' => $this->month,
            'min_days' => $this->minDays,
            'kind' => $this->kind,
            'borrower_filter' => $this->borrower !== null,
            'item_filter' => $this->item !== null,
        ];
    }
}
