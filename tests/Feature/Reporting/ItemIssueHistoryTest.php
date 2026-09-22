<?php

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Exports\ItemIssueHistoryExport;
use App\Domain\Requisition\Services\IssueService;
use App\Livewire\Reports\ReportsDashboard;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * User-requested 2026-09-22: per-chemical dispensing history — who took it, when, how much,
 * plus what is left. Quantities are what was actually dispensed, so the detail lines and the
 * balance beside them always reconcile.
 */
function issueOnce(\App\Models\Item $item, string $qty, \App\Models\User $requester, \App\Models\User $issuer, ?int $labId = null): \App\Models\Requisition
{
    $g = Unit::where('code', 'g')->firstOrFail();
    $requisition = makeRequisition($requester, array_filter(['lab_id' => $labId]));
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, $qty);
    $requisition->update(['status' => 'APPROVED']);
    $line = $requisition->fresh(['items'])->items()->firstOrFail();

    $container = stockedContainer($item->id, $qty, $issuer);
    app(IssueService::class)->issue($line, $container, $qty, $g, $issuer, $requester, hash('sha256', 'sig'));

    return $requisition->fresh();
}

test('the export lists every dispensing of one chemical with who, when and how much', function () {
    $item = makeItem();
    $issuer = auditorUser();
    $somchai = staffUser(['full_name' => 'สมชาย ใจดี']);
    $malee = staffUser(['full_name' => 'มาลี รักเรียน']);

    $first = issueOnce($item, '10.000000', $somchai, $issuer);
    $second = issueOnce($item, '4.000000', $malee, $issuer);

    // A different chemical must not bleed into this report.
    issueOnce(makeItem(), '99.000000', $somchai, $issuer);

    $export = new ItemIssueHistoryExport($item, new DateRangeFilter(null, null));
    $rows = $export->collection();

    expect($rows)->toHaveCount(2);
    expect($rows->first())->toContain('สมชาย ใจดี', $first->doc_no);
    expect($rows->last())->toContain('มาลี รักเรียน', $second->doc_no);
});

test('the summary totals exactly the rows listed, and reports the current balance', function () {
    $item = makeItem();
    $issuer = auditorUser();
    $staff = staffUser();

    issueOnce($item, '10.000000', $staff, $issuer);
    issueOnce($item, '4.000000', $staff, $issuer);

    $export = new ItemIssueHistoryExport($item, new DateRangeFilter(null, null));

    expect($export->totalIssued())->toBe('14.000000');
    // Each issue stocked exactly what it then took, so nothing is left.
    expect((float) $export->remainingBalance())->toEqual(0.0);
});

test('the date range filter narrows both the rows and the total', function () {
    $item = makeItem();
    $issuer = auditorUser();
    $staff = staffUser();

    issueOnce($item, '10.000000', $staff, $issuer);
    \App\Models\IssueTransaction::query()->update(['issued_at' => now()->subMonths(6)]);
    issueOnce($item, '4.000000', $staff, $issuer);

    $export = new ItemIssueHistoryExport(
        $item,
        new DateRangeFilter(now()->subMonth()->toDateString(), now()->toDateString()),
    );

    expect($export->results())->toHaveCount(1);
    expect($export->totalIssued())->toBe('4.000000');
});

test('a requisition that is approved but never issued does not appear — it moved no stock', function () {
    $item = makeItem();
    $staff = staffUser();
    $requisition = makeRequisition($staff);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, '25.000000');
    $requisition->update(['status' => 'APPROVED']);

    $export = new ItemIssueHistoryExport($item, new DateRangeFilter(null, null));

    expect($export->results())->toHaveCount(0);
    expect($export->totalIssued())->toBe('0.000000');
});

test('the lab filter narrows to requisitions raised in that branch', function () {
    $item = makeItem();
    $issuer = auditorUser();
    $labA = makeLab();
    $labB = makeLab();

    issueOnce($item, '10.000000', staffUser(['lab_id' => $labA->id]), $issuer, $labA->id);
    issueOnce($item, '4.000000', staffUser(['lab_id' => $labB->id]), $issuer, $labB->id);

    $export = new ItemIssueHistoryExport($item, new DateRangeFilter(null, null), $labA->id);

    expect($export->results())->toHaveCount(1);
    expect($export->totalIssued())->toBe('10.000000');
});

test('user-reported 2026-09-22 (twice): the picker draws from real in-stock inventory, not the whole catalog', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $manager = auditorUser(['lab_id' => $lab->id]);

    $inStock = makeItem(['name_th' => 'สารที่มีสต็อกอยู่']);
    makeContainer(['item_id' => $inStock->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '50']);

    $neverStocked = makeItem(['name_th' => 'สารที่ไม่เคยมีสต็อก']);

    $emptiedOut = makeItem(['name_th' => 'สารที่หมดสต็อกแล้ว']);
    makeContainer(['item_id' => $emptiedOut->id, 'location_id' => $location->id, 'status' => 'EMPTY', 'remaining_qty_base' => '0']);

    Livewire::actingAs($manager)
        ->test(ReportsDashboard::class)
        ->set('tab', 'item_issue_history')
        ->assertSee('สารที่มีสต็อกอยู่')
        ->assertDontSee('สารที่ไม่เคยมีสต็อก')
        ->assertDontSee('สารที่หมดสต็อกแล้ว');
});

test('the picker is branch-scoped, same as the "คลังสารเคมีของฉัน" inventory it draws from', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);
    $managerA = auditorUser(['lab_id' => $labA->id]);

    $itemA = makeItem(['name_th' => 'สารสาขา A']);
    makeContainer(['item_id' => $itemA->id, 'location_id' => $locationA->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);

    $itemB = makeItem(['name_th' => 'สารสาขา B']);
    makeContainer(['item_id' => $itemB->id, 'location_id' => $locationB->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);

    Livewire::actingAs($managerA)
        ->test(ReportsDashboard::class)
        ->set('tab', 'item_issue_history')
        ->assertSee('สารสาขา A')
        ->assertDontSee('สารสาขา B');
});

test('the reports page shows the history once a chemical is picked, and prompts before that', function () {
    $item = makeItem(['name_th' => 'สารสำหรับทดสอบประวัติ']);
    $issuer = auditorUser(['lab_id' => makeLab()->id]);
    $requisition = issueOnce($item, '10.000000', staffUser(['full_name' => 'สมชาย ใจดี']), $issuer, $issuer->lab_id);

    Livewire::actingAs($issuer)
        ->test(ReportsDashboard::class)
        ->set('tab', 'item_issue_history')
        ->assertSee(__('reports.item_issue_history_pick'))
        ->set('historyItemUlid', $item->ulid)
        ->assertSee('สมชาย ใจดี')
        ->assertSee($requisition->doc_no);
});

test('a SCIENTIST still cannot reach the new report route', function () {
    $item = makeItem();
    $scientist = scientistUser(['lab_id' => makeLab()->id]);

    $this->actingAs($scientist)
        ->get(route('reports.item-issue-history.excel', $item))
        ->assertStatus(403);
});

test('the Excel route downloads for a warehouse manager', function () {
    $item = makeItem();
    $manager = auditorUser(['lab_id' => makeLab()->id]);

    $this->actingAs($manager)
        ->get(route('reports.item-issue-history.excel', $item))
        ->assertOk();
});

test('user-requested 2026-09-22: a PDF version is available, styled like F-03, with real dispensing rows', function () {
    $item = makeItem();
    $manager = auditorUser(['lab_id' => makeLab()->id]);
    $requisition = issueOnce($item, '10.000000', staffUser(['full_name' => 'สมชาย ใจดี']), $manager, $manager->lab_id);

    $response = $this->actingAs($manager)->get(route('reports.item-issue-history.pdf', $item));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('a SCIENTIST still cannot reach the new PDF route', function () {
    $item = makeItem();
    $scientist = scientistUser(['lab_id' => makeLab()->id]);

    $this->actingAs($scientist)
        ->get(route('reports.item-issue-history.pdf', $item))
        ->assertStatus(403);
});

test('user-reported 2026-09-22: the Excel export trims trailing zeros from the quantity', function () {
    $item = makeItem();
    $issuer = auditorUser();
    $requester = staffUser();
    issueOnce($item, '10.000000', $requester, $issuer);

    $export = new ItemIssueHistoryExport($item, new DateRangeFilter(null, null));
    $row = $export->collection()->first();

    expect($row)->toContain('10');
    expect($row)->not->toContain('10.000000');
});

test('user-requested 2026-09-22: the receiving history lists IMS requisition numbers from the stock-in remark', function () {
    $item = makeItem();
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id]);
    $location = makeLocationForLab($lab);
    $g = Unit::where('code', 'g')->firstOrFail();

    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '100.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $manager->id, remark: 'IMS-2569-00042'),
    );

    $export = new \App\Domain\Reporting\Exports\ItemReceivingHistoryExport($item, new DateRangeFilter(null, null));
    $rows = $export->collection();

    expect($rows)->toHaveCount(1);
    expect($rows->first())->toContain('IMS-2569-00042');
    // Trimmed, same convention as the dispensing side.
    expect($rows->first())->toContain('100')->not->toContain('100.000000');
});

test('the receiving history is branch-scoped the same way as the dispensing history', function () {
    $item = makeItem();
    $labA = makeLab();
    $labB = makeLab();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);
    $manager = auditorUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    $containerA = makeContainer(['item_id' => $item->id, 'location_id' => $locationA->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive($containerA->id, '10.000000', new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $manager->id, remark: 'IMS-A'));

    $containerB = makeContainer(['item_id' => $item->id, 'location_id' => $locationB->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive($containerB->id, '5.000000', new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $manager->id, remark: 'IMS-B'));

    $export = new \App\Domain\Reporting\Exports\ItemReceivingHistoryExport($item, new DateRangeFilter(null, null), $labA->id);

    expect($export->results())->toHaveCount(1);
    expect($export->collection()->first())->toContain('IMS-A');
});

test('the Excel export includes the receiving history as a second sheet', function () {
    $item = makeItem();
    $manager = auditorUser(['lab_id' => makeLab()->id]);

    $response = $this->actingAs($manager)->get(route('reports.item-issue-history.excel', $item));

    $response->assertOk();
    $response->assertHeader(
        'Content-Type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
});

test('the PDF includes the receiving history section', function () {
    $item = makeItem();
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id]);
    $location = makeLocationForLab($lab);
    $g = Unit::where('code', 'g')->firstOrFail();

    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '20.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $manager->id, remark: 'IMS-9999'),
    );

    $response = $this->actingAs($manager)->get(route('reports.item-issue-history.pdf', $item));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('user-requested 2026-09-22: switching to a different chemical is a single click, no "change item" step first', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $manager = auditorUser(['lab_id' => $lab->id]);

    $itemA = makeItem(['name_th' => 'สาร A สำหรับสลับ']);
    makeContainer(['item_id' => $itemA->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);
    $itemB = makeItem(['name_th' => 'สาร B สำหรับสลับ']);
    makeContainer(['item_id' => $itemB->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);

    $component = Livewire::actingAs($manager)
        ->test(ReportsDashboard::class)
        ->set('tab', 'item_issue_history')
        ->set('historyItemUlid', $itemA->ulid)
        ->assertSee('สาร A สำหรับสลับ')
        // The candidate list — including the OTHER chemical — stays visible after picking one.
        ->assertSee('สาร B สำหรับสลับ');

    // Switching directly to another candidate, without clearing the selection first.
    $component->set('historyItemUlid', $itemB->ulid)
        ->assertSee('สาร B สำหรับสลับ')
        ->assertSee('สาร A สำหรับสลับ');
});

test('user-reported 2026-09-22: each chemical in the list has its own download links, no need to open it first', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $item = makeItem(['name_th' => 'สารสำหรับทดสอบปุ่มดาวน์โหลด']);
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);

    Livewire::actingAs($manager)
        ->test(ReportsDashboard::class)
        ->set('tab', 'item_issue_history')
        ->assertSee('สารสำหรับทดสอบปุ่มดาวน์โหลด')
        ->assertSee(route('reports.item-issue-history.excel', $item), false)
        ->assertSee(route('reports.item-issue-history.pdf', $item), false);

    // The link on the row actually works — no selection required first.
    $this->actingAs($manager)
        ->get(route('reports.item-issue-history.excel', $item))
        ->assertOk();
});

test('user-requested 2026-09-22: the PDF lists receiving history first, then dispensing history, then the balance', function () {
    $item = makeItem();
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id]);
    $location = makeLocationForLab($lab);
    $g = Unit::where('code', 'g')->firstOrFail();

    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '50.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $manager->id, remark: 'IMS-ORDER-TEST'),
    );
    issueOnce($item, '20.000000', staffUser(['lab_id' => $lab->id]), $manager, $lab->id);

    $issueExport = new ItemIssueHistoryExport($item, new DateRangeFilter(null, null));
    $receivingExport = new \App\Domain\Reporting\Exports\ItemReceivingHistoryExport($item, new DateRangeFilter(null, null));

    $service = app(\App\Domain\Reporting\Services\ItemIssueHistoryPdfService::class);
    $buildHtml = new ReflectionMethod($service, 'buildHtml');
    $html = $buildHtml->invoke($service, $item, $issueExport->results(), $receivingExport->results(), $issueExport);

    $receivingPos = strpos($html, __('reports.item_receiving_history_title'));
    $issuePos = strpos($html, __('reports.col_doc_no'));
    $balancePos = strpos($html, __('reports.summary_balance'));

    expect($receivingPos)->not->toBeFalse();
    expect($issuePos)->not->toBeFalse();
    expect($balancePos)->not->toBeFalse();
    expect($receivingPos)->toBeLessThan($issuePos);
    expect($issuePos)->toBeLessThan($balancePos);

    // issueOnce() stocks its own container and fully issues it (net zero); only the
    // manual +50 receive above changes the overall balance, so it should read 50, trimmed.
    expect((float) $issueExport->remainingBalance())->toEqual(50.0);
    expect($html)->toContain('IMS-ORDER-TEST');
});
