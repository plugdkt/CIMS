<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTO;

/** §7.8 "สรุปการใช้": filter by requester / project / course / faculty / date range. */
final readonly class UsageSummaryFilter
{
    public function __construct(
        public ?string $requesterName = null,
        public ?string $purposeDetail = null,
        public ?string $faculty = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {
    }
}
