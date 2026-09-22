<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Models\Item;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * User-requested 2026-09-22: the item's dispensing history report, with a second sheet for
 * its receiving history (IMS requisition number) appended — "แนบท้ายรายงาน" — rather than a
 * separate download. Two sheets so each table keeps its own, unrelated set of headings.
 *
 * Implements the (otherwise-empty) `Export` marker interface explicitly — this installed
 * version of maatwebsite/excel types `Excel::download()`'s parameter as `Export`, and
 * `WithMultipleSheets` alone does not extend it (unlike `FromCollection`, which does).
 */
final class ItemStockCardExport implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly Item $item,
        private readonly DateRangeFilter $period,
        private readonly ?int $labId = null,
    ) {
    }

    /** @return array<int, \Maatwebsite\Excel\Concerns\FromCollection> */
    public function sheets(): array
    {
        return [
            new ItemIssueHistoryExport($this->item, $this->period, $this->labId),
            new ItemReceivingHistoryExport($this->item, $this->period, $this->labId),
        ];
    }
}
