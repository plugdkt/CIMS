<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a scientist (no ledger.adjust) gets 403 on the create page', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('adjustments.create'))->assertStatus(403);
});

test('FT-07: a LAB_MANAGER records an adjustment naming a different LAB_MANAGER as approver', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    $response = $this->actingAs($creator)->post(route('adjustments.store'), [
        'barcode' => $container->fresh()->barcode,
        'direction' => 'OUT',
        'qty' => '5.000000',
        'remark' => 'พบของขาดจากตรวจนับประจำเดือน',
        'approved_by' => $approver->id,
    ]);

    $response->assertRedirect(route('adjustments.index'));
    expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
    $row = StockLedger::where('item_id', $item->id)->where('txn_type', 'ADJUST_OUT')->firstOrFail();
    expect($row->approved_by)->toBe($approver->id);

    $this->actingAs($creator)->get(route('adjustments.index'))->assertOk()->assertSee($item->name_th);
});

test('FT-08: an adjustment where the approver is the same as the creator is rejected with HTTP redirect + errors (BR-06)', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $labManager = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $labManager->id));

    $response = $this->actingAs($labManager)->post(route('adjustments.store'), [
        'barcode' => $container->fresh()->barcode,
        'direction' => 'IN',
        'qty' => '5.000000',
        'remark' => 'พบของเกินจากตรวจนับประจำเดือน',
        'approved_by' => $labManager->id,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('adjustment');
    expect(StockLedger::where('item_id', $item->id)->where('txn_type', 'ADJUST_IN')->count())->toBe(0);
});

test('naming an approver who does not hold ledger.adjust is rejected', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $labManager = labManagerUser();
    $scientist = scientistUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $labManager->id));

    $response = $this->actingAs($labManager)->post(route('adjustments.store'), [
        'barcode' => $container->fresh()->barcode,
        'direction' => 'IN',
        'qty' => '5.000000',
        'remark' => 'พบของเกินจากตรวจนับประจำเดือน',
        'approved_by' => $scientist->id,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('adjustment');
});

test('a remark shorter than 10 characters fails validation before reaching the service', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    $this->actingAs($creator)->post(route('adjustments.store'), [
        'barcode' => $container->fresh()->barcode,
        'direction' => 'IN',
        'qty' => '5.000000',
        'remark' => 'สั้น',
        'approved_by' => $approver->id,
    ])->assertSessionHasErrors('remark');
});

test('an unknown barcode is rejected with a validation error', function () {
    $labManager = labManagerUser();
    $approver = labManagerUser();

    $this->actingAs($labManager)->post(route('adjustments.store'), [
        'barcode' => 'BC-NOT-REAL',
        'direction' => 'IN',
        'qty' => '5.000000',
        'remark' => 'พบของเกินจากตรวจนับประจำเดือน',
        'approved_by' => $approver->id,
    ])->assertSessionHasErrors('barcode');
});
