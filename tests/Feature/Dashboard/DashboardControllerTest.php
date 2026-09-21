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

test('§7.9: a SCIENTIST (report.view) sees the full dashboard with all analytics cards', function () {
    $scientist = scientistUser();

    $response = $this->actingAs($scientist)->get('/');

    $response->assertOk()->assertViewIs('home');
    $response->assertViewHas('belowReorderCount');
    $response->assertViewHas('expiringCount');
    $response->assertViewHas('topItems');
    $response->assertViewHas('monthlySeries');
    $response->assertSee(__('home.below_reorder'));
    $response->assertSee(__('home.monthly_chart_title'));
});

test('§7.9 (user-requested 2026-09-21): the dashboard names which items are actually low/expiring, not just a count', function () {
    $scientist = scientistUser();
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

    $response = $this->actingAs($scientist)->get('/');

    $response->assertOk()
        ->assertSee('สารใกล้หมดสำหรับทดสอบ')
        ->assertSee('สารใกล้หมดอายุสำหรับทดสอบ');
});
