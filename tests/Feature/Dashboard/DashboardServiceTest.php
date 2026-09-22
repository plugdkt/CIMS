<?php

use App\Domain\Reporting\Services\DashboardService;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('§7.9: an ADVISOR sees only their own SUBMITTED advisees as pending', function () {
    $student = studentUser();
    $advisor = $student->advisor;
    $otherAdvisor = User::factory()->create();
    $otherAdvisor->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());

    submittedRequisition($student); // advisor_id = $advisor->id, SUBMITTED
    submittedRequisition(studentUser(['advisor_id' => $otherAdvisor->id])); // a different advisor's advisee

    expect(app(DashboardService::class)->pendingRequisitionsCount($advisor))->toBe(1);
});

test('§7.9 (updated 2026-09-21: requisition review moved from SCIENTIST to warehouse managers): a warehouse manager (AUDITOR) sees ADVISOR_APPROVED plus non-student SUBMITTED requisitions in their own branch', function () {
    $lab = makeLab();
    $auditor = auditorUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => $lab->id]);
    $staff = staffUser(['lab_id' => $lab->id]);

    submittedRequisition($student, ['lab_id' => $lab->id])->update(['status' => 'ADVISOR_APPROVED']);
    submittedRequisition($staff, ['lab_id' => $lab->id]); // non-student, stays SUBMITTED — warehouse manager can act directly
    submittedRequisition($student, ['lab_id' => $lab->id]); // student, still SUBMITTED — advisor hasn't acted, not their turn yet

    expect(app(DashboardService::class)->pendingRequisitionsCount($auditor))->toBe(2);
});

test('§7.9 (updated 2026-09-21): pendingRequisitionsCount also counts requisitions awaiting issuance (APPROVED/PARTIALLY_ISSUED) for a requisition.view_all holder who also holds requisition.issue', function () {
    // No seeded role currently combines requisition.view_all with requisition.issue — this
    // exercises DashboardService's own branch directly rather than asserting it against
    // a real-world role combination that doesn't exist post-2026-09-21 restructuring.
    $lab = makeLab();
    $auditor = auditorUser(['lab_id' => $lab->id]);
    Role::where('code', 'AUDITOR')->firstOrFail()->permissions()
        ->attach(\App\Models\Permission::where('code', 'requisition.issue')->firstOrFail());

    submittedRequisition(staffUser(['lab_id' => $lab->id]), ['lab_id' => $lab->id])->update(['status' => 'APPROVED']);
    submittedRequisition(staffUser(['lab_id' => $lab->id]), ['lab_id' => $lab->id])->update(['status' => 'PARTIALLY_ISSUED']);
    submittedRequisition(staffUser(['lab_id' => $lab->id]), ['lab_id' => $lab->id])->update(['status' => 'ISSUED']);

    expect(app(DashboardService::class)->pendingRequisitionsCount($auditor->fresh()))->toBe(2);
});

test('§7.9: a plain requester (no action permission) sees only their own in-flight requisitions', function () {
    $staff = staffUser();
    submittedRequisition($staff); // SUBMITTED — in flight
    submittedRequisition($staff)->update(['status' => 'ISSUED']); // done — not counted
    submittedRequisition(staffUser()); // someone else's — not counted

    expect(app(DashboardService::class)->pendingRequisitionsCount($staff))->toBe(1);
});

test('§7.9: a LAB_MANAGER (no requisition action permission) sees 0, not an error', function () {
    $labManager = labManagerUser();

    expect(app(DashboardService::class)->pendingRequisitionsCount($labManager))->toBe(0);
});

test('§7.9: below-reorder count matches the below-reorder-point report', function () {
    $item = makeItem(['reorder_point_base' => '20.000000']);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '5.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id),
    );

    expect(app(DashboardService::class)->belowReorderPointCount())->toBe(1);
});

test('§7.9: expiring-within-30-days count excludes containers outside the window', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(20)->toDateString()]);
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(45)->toDateString()]);

    expect(app(DashboardService::class)->expiringWithin30DaysCount())->toBe(1);
});

test('§7.9 (user-requested 2026-09-21): belowReorderPointItems lists the flagged items, most urgent first', function () {
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    $barelyLow = makeItem(['name_th' => 'สารเกือบพอ', 'reorder_point_base' => '100.000000']);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        makeContainer(['item_id' => $barelyLow->id])->id,
        '90.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id),
    );

    $critical = makeItem(['name_th' => 'สารใกล้หมดวิกฤต', 'reorder_point_base' => '100.000000']);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        makeContainer(['item_id' => $critical->id])->id,
        '5.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id),
    );

    $wellStocked = makeItem(['name_th' => 'สารเหลือเยอะ', 'reorder_point_base' => '100.000000']);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        makeContainer(['item_id' => $wellStocked->id])->id,
        '500.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id),
    );

    $items = app(DashboardService::class)->belowReorderPointItems();

    expect($items)->toHaveCount(2);
    expect($items->first()['item']->id)->toBe($critical->id);
    expect($items->last()['item']->id)->toBe($barelyLow->id);
});

test('§7.9 (user-requested 2026-09-21): expiringWithin30DaysContainers lists the containers, soonest first', function () {
    $item = makeItem();
    $soon = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(5)->toDateString()]);
    $later = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(25)->toDateString()]);
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(45)->toDateString()]); // outside window

    $containers = app(DashboardService::class)->expiringWithin30DaysContainers();

    expect($containers)->toHaveCount(2);
    expect($containers->first()->id)->toBe($soon->id);
    expect($containers->last()->id)->toBe($later->id);
});

test('§7.9: top issued items are ranked by issue frequency within the last 3 months', function () {
    $staff = staffUser();
    $popular = makeItem();
    $rare = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $scientist = scientistUser();

    foreach ([$popular, $popular, $popular, $rare] as $item) {
        $requisition = makeRequisition($staff);
        app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, '1.000000');
        $requisition->update(['status' => 'APPROVED']);
        $line = $requisition->items->first();
        $container = stockedContainer($line->item_id, '100.000000', $staff);
        app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '1.000000', $g, $scientist, $staff, 'sig');
    }

    $top = app(DashboardService::class)->topIssuedItems(10);

    expect($top->first()['item']->id)->toBe($popular->id);
    expect($top->first()['issue_count'])->toBe(3);
    expect($top->first()['qty_issued'])->toBe('3.000000');
    expect($top->last()['qty_issued'])->toBe('1.000000');
});

test('§7.9: an issue transaction older than 3 months does not count toward top items', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $staff = staffUser();
    $scientist = scientistUser();
    $requisition = makeRequisition($staff);
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, '1.000000');
    $requisition->update(['status' => 'APPROVED']);
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $issue = app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '1.000000', $g, $scientist, $staff, 'sig');
    $issue->update(['issued_at' => now()->subMonths(4)]);

    expect(app(DashboardService::class)->topIssuedItems(10))->toHaveCount(0);
});

test('§7.9: the monthly issuance series covers 12 trailing months, oldest first, with correct counts', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $staff = staffUser();
    $scientist = scientistUser();
    $requisition = makeRequisition($staff);
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, '1.000000');
    $requisition->update(['status' => 'APPROVED']);
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '1.000000', $g, $scientist, $staff, 'sig');

    $series = app(DashboardService::class)->monthlyIssuanceSeries();

    expect($series)->toHaveCount(12);
    expect($series->last()['month']->format('Y-m'))->toBe(now()->format('Y-m'));
    expect($series->last()['count'])->toBe(1);
    expect($series->first()['month']->lt($series->last()['month']))->toBeTrue();
});
