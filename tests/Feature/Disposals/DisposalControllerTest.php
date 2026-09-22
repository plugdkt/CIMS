<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * User-requested 2026-09-22: disposals belong to the warehouse managers (LAB_MANAGER/
 * AUDITOR) and ADMIN. SCIENTIST no longer requests them, and a warehouse manager may
 * both request and approve — the requester/approver split that used to be the separation
 * of duties here was deliberately dropped ("คนเดียวทำได้จบ"). ADMIN is the one role that
 * can request but never approve, because approving writes to the ledger (spec §3).
 */
test('a SCIENTIST, who no longer holds disposal.request, gets 403 on the create page', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('disposals.create'))->assertStatus(403);
});

test('a warehouse manager can request a disposal by barcode', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $auditor = auditorUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $auditor->id));

    $response = $this->actingAs($auditor)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'EXPIRED',
        'method' => 'เผาทำลาย',
        'disposal_date' => now()->toDateString(),
    ]);

    $response->assertRedirect();
    $this->actingAs($auditor)->get(route('disposals.index'))->assertOk()->assertSee($item->name_th);
});

test('a LAB_MANAGER can approve, and the container is credited down through the HTTP layer', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $auditor = auditorUser();
    $labManager = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $auditor->id));

    $this->actingAs($auditor)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'DAMAGED',
        'disposal_date' => now()->toDateString(),
    ]);
    $disposal = \App\Models\Disposal::firstOrFail();

    $this->actingAs($labManager)->post(route('disposals.approve', $disposal))
        ->assertRedirect(route('disposals.show', $disposal));

    expect($container->fresh()->remaining_qty_base)->toBe('30.000000');
    expect($disposal->fresh()->status)->toBe('APPROVED');
});

test('user-decided 2026-09-22: one warehouse manager can both request and approve the same disposal', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $auditor = auditorUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $auditor->id));

    $this->actingAs($auditor)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'EXPIRED',
        'disposal_date' => now()->toDateString(),
    ]);
    $disposal = \App\Models\Disposal::firstOrFail();

    $this->actingAs($auditor)->post(route('disposals.approve', $disposal))
        ->assertRedirect(route('disposals.show', $disposal));

    expect($disposal->fresh()->status)->toBe('APPROVED');
});

test('branch scoping: a LAB_MANAGER of a different branch gets 403 approving a disposal in another branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requester = auditorUser(['lab_id' => $lab->id]);
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id));

    $this->actingAs($requester)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'EXPIRED',
        'disposal_date' => now()->toDateString(),
    ]);
    $disposal = \App\Models\Disposal::firstOrFail();

    $otherManager = labManagerUser(['lab_id' => $otherLab->id]);
    $this->actingAs($otherManager)->post(route('disposals.approve', $disposal))->assertStatus(403);

    $ownManager = labManagerUser(['lab_id' => $lab->id]);
    $this->actingAs($ownManager)->post(route('disposals.approve', $disposal))
        ->assertRedirect(route('disposals.show', $disposal));
});

test('spec §3: an ADMIN can request a disposal but never approve one — approving writes to the ledger', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $admin = adminUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $admin->id));

    $this->actingAs($admin)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'WASTE',
        'disposal_date' => now()->toDateString(),
    ])->assertRedirect();

    $disposal = \App\Models\Disposal::firstOrFail();

    $this->actingAs($admin)->post(route('disposals.approve', $disposal))->assertStatus(403);
    expect($disposal->fresh()->status)->toBe('PENDING');
});

test('an unknown barcode is rejected with a validation error', function () {
    $auditor = auditorUser();

    $this->actingAs($auditor)->post(route('disposals.store'), [
        'barcode' => 'BC-NOT-REAL',
        'qty' => '5.000000',
        'reason' => 'OTHER',
        'disposal_date' => now()->toDateString(),
    ])->assertSessionHasErrors('barcode');
});
