<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without disposal.request gets 403 on the create page', function () {
    $labManager = labManagerUser();

    $this->actingAs($labManager)->get(route('disposals.create'))->assertStatus(403);
});

test('a scientist can request a disposal by barcode', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $scientist = scientistUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $scientist->id));

    $response = $this->actingAs($scientist)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'EXPIRED',
        'method' => 'เผาทำลาย',
        'disposal_date' => now()->toDateString(),
    ]);

    $response->assertRedirect();
    $this->actingAs($scientist)->get(route('disposals.index'))->assertOk()->assertSee($item->name_th);
});

test('a LAB_MANAGER can approve, and the container is credited down through the HTTP layer', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $scientist = scientistUser();
    $labManager = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $scientist->id));

    $this->actingAs($scientist)->post(route('disposals.store'), [
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

test('a scientist (no disposal.approve permission) cannot approve, even their own request', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $scientist = scientistUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $scientist->id));

    $this->actingAs($scientist)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'WASTE',
        'disposal_date' => now()->toDateString(),
    ]);
    $disposal = \App\Models\Disposal::firstOrFail();

    $this->actingAs($scientist)->post(route('disposals.approve', $disposal))->assertStatus(403);
});

test('an unknown barcode is rejected with a validation error', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->post(route('disposals.store'), [
        'barcode' => 'BC-NOT-REAL',
        'qty' => '5.000000',
        'reason' => 'OTHER',
        'disposal_date' => now()->toDateString(),
    ])->assertSessionHasErrors('barcode');
});
