<?php

use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without receiving.manage gets 403 on the GRN index and create page', function () {
    $manager = labManagerUser();

    $this->actingAs($manager)->get(route('goods-receipts.index'))->assertStatus(403);
    $this->actingAs($manager)->get(route('goods-receipts.create'))->assertStatus(403);
});

test('SCIENTIST can create a GRN header with an auto-generated doc_no', function () {
    $scientist = scientistUser();
    $lab = makeLab();

    $response = $this->actingAs($scientist)->post(route('goods-receipts.store'), [
        'receipt_date' => now()->toDateString(),
        'lab_id' => $lab->id,
        'supplier' => 'บริษัท ทดสอบ จำกัด',
    ]);

    $grn = GoodsReceipt::where('lab_id', $lab->id)->first();
    expect($grn)->not->toBeNull();
    expect($grn->doc_no)->toStartWith('GRN-');
    expect($grn->status)->toBe('DRAFT');
    $response->assertRedirect(route('goods-receipts.show', $grn));
});

test('SCIENTIST can add a line, confirm the GRN, and containers + ledger get created', function () {
    $scientist = scientistUser();
    $lab = makeLab();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $unit = Unit::where('code', 'g')->firstOrFail();

    $store = $this->actingAs($scientist)->post(route('goods-receipts.store'), [
        'receipt_date' => now()->toDateString(),
        'lab_id' => $lab->id,
    ]);
    $grn = GoodsReceipt::where('lab_id', $lab->id)->firstOrFail();

    $this->actingAs($scientist)->post(route('goods-receipts.items.store', $grn), [
        'item_id' => $item->id,
        'container_count' => 2,
        'qty_per_container' => '5',
        'unit_id' => $unit->id,
    ])->assertRedirect(route('goods-receipts.show', $grn));

    $this->actingAs($scientist)->post(route('goods-receipts.confirm', $grn))
        ->assertRedirect(route('goods-receipts.show', $grn));

    expect($grn->fresh()->status)->toBe('CONFIRMED');
    expect(Container::where('item_id', $item->id)->count())->toBe(2);
});

test('a CONFIRMED GRN cannot be edited, have lines added, or be cancelled (FR-RC-06)', function () {
    $scientist = scientistUser();
    $grn = makeDraftGrn(['status' => 'CONFIRMED']);
    $item = makeItem();
    $unit = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->put(route('goods-receipts.update', $grn), [
        'receipt_date' => now()->toDateString(),
        'lab_id' => $grn->lab_id,
    ])->assertStatus(403);

    $this->actingAs($scientist)->post(route('goods-receipts.items.store', $grn), [
        'item_id' => $item->id,
        'container_count' => 1,
        'qty_per_container' => '1',
        'unit_id' => $unit->id,
    ])->assertStatus(403);

    $this->actingAs($scientist)->post(route('goods-receipts.cancel', $grn))
        ->assertStatus(403);
    expect($grn->fresh()->status)->toBe('CONFIRMED');
});

test('confirming a DRAFT GRN with no lines is rejected', function () {
    $scientist = scientistUser();
    $grn = makeDraftGrn();

    $this->actingAs($scientist)->post(route('goods-receipts.confirm', $grn))
        ->assertSessionHasErrors('confirm');
    expect($grn->fresh()->status)->toBe('DRAFT');
});

test('SCIENTIST can cancel a DRAFT GRN', function () {
    $scientist = scientistUser();
    $grn = makeDraftGrn();

    $this->actingAs($scientist)->post(route('goods-receipts.cancel', $grn))
        ->assertRedirect(route('goods-receipts.index'));

    expect($grn->fresh()->status)->toBe('CANCELLED');
});

test('SCIENTIST can download a container label PDF for a confirmed GRN (FR-RC-04)', function () {
    $scientist = scientistUser();
    $lab = makeLab();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $unit = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('goods-receipts.store'), [
        'receipt_date' => now()->toDateString(),
        'lab_id' => $lab->id,
    ]);
    $grn = GoodsReceipt::where('lab_id', $lab->id)->firstOrFail();

    $this->actingAs($scientist)->post(route('goods-receipts.items.store', $grn), [
        'item_id' => $item->id,
        'container_count' => 2,
        'qty_per_container' => '5',
        'unit_id' => $unit->id,
    ]);
    $this->actingAs($scientist)->post(route('goods-receipts.confirm', $grn));

    $response = $this->actingAs($scientist)->get(route('goods-receipts.labels', [$grn, '40x25']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('an unknown label size 404s', function () {
    $scientist = scientistUser();
    $grn = makeDraftGrn();

    $this->actingAs($scientist)->get(route('goods-receipts.labels', [$grn, '99x99']))
        ->assertStatus(404);
});

test('a DRAFT GRN with no containers yet 404s on the labels route', function () {
    $scientist = scientistUser();
    $grn = makeDraftGrn();

    $this->actingAs($scientist)->get(route('goods-receipts.labels', [$grn, '40x25']))
        ->assertStatus(404);
});
