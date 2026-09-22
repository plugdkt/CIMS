<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest sees the plain welcome page at /', function () {
    $this->get('/')->assertOk()->assertViewIs('welcome');
});

test('user-reported 2026-09-21: the sidebar always has a way back to the dashboard, from any page', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('dashboard'))->assertOk();

    // Navigate to a completely different page — the dashboard link must still be there.
    $this->actingAs($scientist)->get(route('items.index'))
        ->assertOk()
        ->assertSee(__('nav.dashboard'))
        ->assertSee(route('dashboard'), false);
});

test('§7.9: a logged-in STUDENT sees the dashboard with a pending-requisitions card but no report.view analytics', function () {
    $student = studentUser();

    $response = $this->actingAs($student)->get('/');

    $response->assertOk()->assertViewIs('home');
    $response->assertViewHas('pendingRequisitions');
    $response->assertViewMissing('belowReorderCount');
    $response->assertViewMissing('topItems');
    $response->assertSee(__('home.pending_requisitions'));
    $response->assertDontSee(__('home.below_reorder'));
});

test('§7.9: a warehouse manager (report.view) sees the full dashboard with all analytics cards', function () {
    $manager = auditorUser();

    $response = $this->actingAs($manager)->get('/');

    $response->assertOk()->assertViewIs('home');
    $response->assertViewHas('belowReorderCount');
    $response->assertViewHas('expiringCount');
    $response->assertViewHas('topItems');
    $response->assertViewHas('monthlySeries');
    $response->assertSee(__('home.below_reorder'));
    $response->assertSee(__('home.monthly_chart_title'));
});

test('§7.9 (user-requested 2026-09-21): the dashboard names which items are actually low/expiring, not just a count', function () {
    $manager = auditorUser();
    $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
    $ledgerUser = \App\Models\User::factory()->create();

    $lowItem = makeItem(['name_th' => 'สารใกล้หมดสำหรับทดสอบ', 'reorder_point_base' => '100.000000']);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        makeContainer(['item_id' => $lowItem->id])->id,
        '5.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $ledgerUser->id),
    );

    $expiringItem = makeItem(['name_th' => 'สารใกล้หมดอายุสำหรับทดสอบ']);
    makeContainer(['item_id' => $expiringItem->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(5)->toDateString()]);

    $response = $this->actingAs($manager)->get('/');

    $response->assertOk()
        ->assertSee('สารใกล้หมดสำหรับทดสอบ')
        ->assertSee('สารใกล้หมดอายุสำหรับทดสอบ');
});

test('dashboard (updated 2026-09-22): a SCIENTIST sees their own requisitions plus their branch\'s waiting to be dispensed', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => $lab->id]);

    // Awaiting issuance in their own branch — genuinely this scientist's pending work.
    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'APPROVED']);

    // Their own in-flight requisition.
    makeRequisition($scientist, ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    // Another branch's, awaiting issuance — not theirs to dispense.
    makeRequisition(studentUser(), ['lab_id' => makeLab()->id, 'status' => 'APPROVED']);

    // Someone else's, still awaiting a warehouse-manager decision — not the scientist's queue.
    makeRequisition(studentUser(), ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    // Already finished — nothing left to do.
    makeRequisition(studentUser(), ['lab_id' => $lab->id, 'status' => 'ISSUED']);

    $response = $this->actingAs($scientist)->get('/');
    $response->assertOk()->assertViewHas('pendingRequisitions', 2);
});

test('dashboard: a warehouse manager (AUDITOR) only sees pending requisitions in their own branch', function () {
    $labA = makeLab();
    $labB = makeLab();
    $auditor = auditorUser(['lab_id' => $labA->id]);
    $studentA = studentUser(['lab_id' => $labA->id]);
    $studentB = studentUser(['lab_id' => $labB->id]);

    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition($studentB, ['lab_id' => $labB->id, 'status' => 'ADVISOR_APPROVED']);

    $response = $this->actingAs($auditor)->get('/');
    $response->assertOk()->assertViewHas('pendingRequisitions', 1);
});

test('dashboard: a warehouse manager (AUDITOR) only sees expiring containers from their own branch', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locA = makeLocationForLab($labA);
    $locB = makeLocationForLab($labB);
    $auditor = auditorUser(['lab_id' => $labA->id]);

    $item = makeItem();
    makeContainer([
        'item_id' => $item->id,
        'location_id' => $locA->id,
        'status' => 'IN_USE',
        'expiry_date' => now()->addDays(5)->toDateString(),
    ]);
    makeContainer([
        'item_id' => $item->id,
        'location_id' => $locB->id,
        'status' => 'IN_USE',
        'expiry_date' => now()->addDays(5)->toDateString(),
    ]);

    $response = $this->actingAs($auditor)->get('/');
    $response->assertOk()->assertViewHas('expiringCount', 1);
});
