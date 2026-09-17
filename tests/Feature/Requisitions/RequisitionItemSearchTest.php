<?php

use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the item search endpoint only returns items with stock in the requester\'s own lab', function () {
    [$student, $ownLab] = studentUserWithLab();
    $otherLab = makeLab();

    $inOwnLab = makeItem(['name_th' => 'โซเดียมไฮดรอกไซด์ AR']);
    stockItemInLab($inOwnLab->id, $ownLab->id);

    $inOtherLab = makeItem(['name_th' => 'โซเดียมคลอไรด์ AR']);
    stockItemInLab($inOtherLab->id, $otherLab->id);

    $response = $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'โซเดียม']));

    $response->assertOk();
    $labels = collect($response->json())->pluck('label');
    expect($labels->contains(fn ($label) => str_contains($label, 'โซเดียมไฮดรอกไซด์ AR')))->toBeTrue();
    expect($labels->contains(fn ($label) => str_contains($label, 'โซเดียมคลอไรด์ AR')))->toBeFalse();
});

test('the item search endpoint matches by item_code too', function () {
    [$student, $ownLab] = studentUserWithLab();
    $item = makeItem(['item_code' => 'CHM-99999']);
    stockItemInLab($item->id, $ownLab->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'CHM-99999']))
        ->assertOk()
        ->assertJsonFragment(['id' => $item->id]);
});

test('an empty query browses the requester\'s own lab stock instead of returning nothing', function () {
    [$student, $ownLab] = studentUserWithLab();
    $item = makeItem();
    stockItemInLab($item->id, $ownLab->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => '']))
        ->assertOk()
        ->assertJsonFragment(['id' => $item->id]);
});

test('a requester with no lab assigned gets nothing back regardless of the query', function () {
    $item = makeItem();
    stockItemInLab($item->id, makeLab()->id);

    $unassigned = studentUser(['lab_id' => null]);
    $this->actingAs($unassigned)->getJson(route('requisitions.items.search', ['q' => $item->name_th]))
        ->assertOk()->assertJson([]);
    $this->actingAs($unassigned)->getJson(route('requisitions.items.search', ['q' => '']))
        ->assertOk()->assertJson([]);
});

test('each result carries the item\'s own base unit id and dimension, for the unit dropdown to match it', function () {
    [$student, $ownLab] = studentUserWithLab();
    $g = Unit::where('code', 'g')->firstOrFail();
    $item = makeItem(['item_code' => 'CHM-88888', 'base_unit_id' => $g->id]);
    stockItemInLab($item->id, $ownLab->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'CHM-88888']))
        ->assertOk()
        ->assertJsonFragment(['baseUnitId' => $item->base_unit_id, 'dimension' => 'MASS']);
});

test('adding a line item for an item with no stock in the requisition\'s own lab is rejected', function () {
    [$student, $ownLab] = studentUserWithLab();
    $requisition = makeRequisition($student, ['lab_id' => $ownLab->id]);
    $otherLab = makeLab();

    $item = makeItem();
    stockItemInLab($item->id, $otherLab->id);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id,
        'qty_requested' => '5',
        'unit_id' => $g->id,
    ])->assertSessionHasErrors('item_id');

    expect($requisition->items()->count())->toBe(0);
});
