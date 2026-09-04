<?php

use App\Domain\Reporting\DTO\UsageSummaryFilter;
use App\Domain\Reporting\Exports\UsageSummaryExport;
use App\Domain\Requisition\Services\IssueService;
use App\Models\Requisition;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function issueOneLine(Requisition $requisition, string $qty = '10.000000'): void
{
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', staffUser());
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    app(IssueService::class)->issue($line, $container, $qty, $g, $scientist, $requisition->requester, 'sig-hash');
}

test('§7.8 usage summary lists every issue transaction with the requester/faculty/purpose', function () {
    $staff = staffUser(['faculty' => 'วิทยาศาสตร์การแพทย์']);
    $requisition = approvedRequisition($staff, '50.000000');
    $requisition->update(['purpose_type' => 'TEACHING', 'purpose_detail' => 'ปฏิบัติการเคมี 1']);
    issueOneLine($requisition->fresh(['items']), '20.000000');

    $rows = (new UsageSummaryExport(new UsageSummaryFilter()))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][1])->toBe($requisition->doc_no);
    expect($rows[0][2])->toBe($staff->full_name);
    expect($rows[0][3])->toBe('วิทยาศาสตร์การแพทย์');
    expect($rows[0][5])->toBe('ปฏิบัติการเคมี 1');
    expect($rows[0][7])->toBe('20.000000');
});

test('filtering by faculty excludes issues from other faculties', function () {
    $medsci = staffUser(['faculty' => 'วิทยาศาสตร์การแพทย์']);
    $eng = staffUser(['faculty' => 'วิศวกรรมศาสตร์']);
    issueOneLine(approvedRequisition($medsci)->fresh(['items']));
    issueOneLine(approvedRequisition($eng)->fresh(['items']));

    $rows = (new UsageSummaryExport(new UsageSummaryFilter(faculty: 'วิทยาศาสตร์การแพทย์')))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][2])->toBe($medsci->full_name);
});

test('filtering by requester name matches a partial name', function () {
    $staff = staffUser(['full_name' => 'สมชาย ใจดี']);
    issueOneLine(approvedRequisition($staff)->fresh(['items']));

    expect((new UsageSummaryExport(new UsageSummaryFilter(requesterName: 'สมชาย')))->collection())->toHaveCount(1);
    expect((new UsageSummaryExport(new UsageSummaryFilter(requesterName: 'ไม่มีตัวตน')))->collection())->toHaveCount(0);
});

test('filtering by date range excludes issues outside the range', function () {
    $staff = staffUser();
    issueOneLine(approvedRequisition($staff)->fresh(['items']));

    $future = (new UsageSummaryExport(new UsageSummaryFilter(dateFrom: now()->addDays(5)->toDateString())))->collection();

    expect($future)->toHaveCount(0);
});

test('free-text fields in the export are CSV-injection guarded', function () {
    $staff = staffUser(['full_name' => '=cmd|/c calc']);
    issueOneLine(approvedRequisition($staff)->fresh(['items']));

    $rows = (new UsageSummaryExport(new UsageSummaryFilter()))->collection();

    expect($rows[0][2])->toStartWith("'=");
});
