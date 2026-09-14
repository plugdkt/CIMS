<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Domain\Reporting\DTO\UsageSummaryFilter;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Models\IssueTransaction;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** §7.8 "สรุปการใช้": every issue transaction, filterable by requester/project/course/faculty/date range. */
final class UsageSummaryExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(private readonly UsageSummaryFilter $filter)
    {
    }

    /** @return Collection<int, array<int, string>> */
    public function collection(): Collection
    {
        $rows = IssueTransaction::query()
            ->with(['requisitionItem.requisition.requester', 'requisitionItem.item'])
            ->whereHas('requisitionItem.requisition', function ($q) {
                if ($this->filter->requesterName !== null && $this->filter->requesterName !== '') {
                    $q->whereHas('requester', fn ($r) => $r->where('full_name', 'like', '%'.$this->filter->requesterName.'%'));
                }
                if ($this->filter->purposeDetail !== null && $this->filter->purposeDetail !== '') {
                    $q->where('purpose_detail', 'like', '%'.$this->filter->purposeDetail.'%');
                }
                if ($this->filter->faculty !== null && $this->filter->faculty !== '') {
                    $q->where('faculty', $this->filter->faculty);
                }
                if ($this->filter->labId !== null) {
                    $q->where('lab_id', $this->filter->labId);
                }
            })
            ->when($this->filter->dateFrom, fn ($q, $from) => $q->whereDate('issued_at', '>=', $from))
            ->when($this->filter->dateTo, fn ($q, $to) => $q->whereDate('issued_at', '<=', $to))
            ->orderBy('issued_at')
            ->get();

        return $rows->map(fn (IssueTransaction $row) => $this->rowToArray($row));
    }

    /** @return array<int, string> */
    private function rowToArray(IssueTransaction $row): array
    {
        $requisitionItem = $row->requisitionItem()->firstOrFail();
        $requisition = $requisitionItem->requisition()->firstOrFail();
        $requester = $requisition->requester()->firstOrFail();
        $item = $requisitionItem->item()->firstOrFail();

        return [
            $row->issued_at->format('d/m/Y'),
            CsvInjectionGuard::sanitize($requisition->doc_no),
            CsvInjectionGuard::sanitize($requester->full_name),
            CsvInjectionGuard::sanitize($requisition->faculty ?? ''),
            (string) __('requisitions.purpose_type_'.strtolower($requisition->purpose_type)),
            CsvInjectionGuard::sanitize($requisition->purpose_detail ?? ''),
            CsvInjectionGuard::sanitize($item->name_th),
            (string) $row->qty_issued_base,
        ];
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            __('reports.col_date'),
            __('reports.col_doc_no'),
            __('reports.col_requester'),
            __('reports.col_faculty'),
            __('reports.col_purpose_type'),
            __('reports.col_purpose_detail'),
            __('reports.col_item'),
            __('reports.col_qty_issued'),
        ];
    }

    public function title(): string
    {
        return mb_substr((string) __('reports.usage_summary_title'), 0, 31);
    }
}
