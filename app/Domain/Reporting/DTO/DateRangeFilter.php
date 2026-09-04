<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTO;

/** A plain date-range filter, shared by every §7.8 report whose only filter is a date range. */
final readonly class DateRangeFilter
{
    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {
    }
}
