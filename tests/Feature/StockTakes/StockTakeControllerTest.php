<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Inventory\Services\StockTakeService;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without stocktake.manage gets 403 on the create page', function () {
    // 2026-09-21: LAB_MANAGER/AUDITOR both gained stocktake.manage (warehouse
    // managers run stock takes too) — use a requester-only role for the negative case.
    $staff = staffUser();

    $this->actingAs($staff)->get(route('stock-takes.create'))->assertStatus(403);
});

test('a scientist can create a round and see it in the list', function () {
    $lab = makeLab();
    $scientist = scientistUser();

    $this->actingAs($scientist)->post(route('stock-takes.store'), [
        'lab_id' => $lab->id,
        'count_date' => now()->toDateString(),
    ])->assertRedirect();

    $this->actingAs($scientist)->get(route('stock-takes.index'))->assertOk()->assertSee($lab->name_th);
});

test('the mobile scan page records a count via barcode and loops back for the next one', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $scientist = scientistUser();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '50']);

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $scientist);

    $this->actingAs($scientist)->get(route('stock-takes.scan', $stockTake))
        ->assertOk()
        ->assertSee(__('stock_takes.scan_progress', ['counted' => 0, 'total' => 1]));

    $this->actingAs($scientist)->post(route('stock-takes.count', $stockTake), [
        'barcode' => $container->barcode,
        'counted_qty' => '48',
    ])->assertRedirect(route('stock-takes.scan', $stockTake));

    $this->actingAs($scientist)->get(route('stock-takes.scan', $stockTake))
        ->assertSee(__('stock_takes.all_counted'));
});

test('a barcode not part of this round is rejected with a clear error', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $scientist = scientistUser();
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '50']);
    $otherContainer = makeContainer(['item_id' => $item->id, 'location_id' => null, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $scientist);

    $this->actingAs($scientist)->post(route('stock-takes.count', $stockTake), [
        'barcode' => $otherContainer->barcode,
        'counted_qty' => '10',
    ])->assertSessionHasErrors('barcode');
});

test('full round trip: create, count, submit, approve writes the adjustment', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $scientist = scientistUser();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '0']);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $scientist->id));

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $scientist);

    $this->actingAs($scientist)->post(route('stock-takes.count', $stockTake), [
        'barcode' => $container->fresh()->barcode,
        'counted_qty' => '45',
        'reason' => 'พบของหายบางส่วน',
    ])->assertRedirect();

    $this->actingAs($scientist)->post(route('stock-takes.submit', $stockTake))->assertRedirect();

    $labManager = labManagerUser();
    $this->actingAs($labManager)->post(route('stock-takes.approve', $stockTake))
        ->assertRedirect(route('stock-takes.show', $stockTake));

    expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
    expect($stockTake->fresh()->status)->toBe('APPROVED');
});
