<?php

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('LAB_MANAGER can save GHS pictograms and H/P statements on an item', function () {
    $manager = labManagerUser();
    $category = \App\Models\ItemCategory::where('code', 'CHEMICAL')->firstOrFail();
    $unit = \App\Models\Unit::where('code', 'g')->firstOrFail();

    $response = $this->actingAs($manager)->post(route('items.store'), [
        'item_code' => 'CHM-GHS01',
        'category_id' => $category->id,
        'name_th' => 'เอทานอล',
        'base_unit_id' => $unit->id,
        'ghs_codes' => ['GHS02', 'GHS07'],
        'h_statements' => ['H225', 'H319'],
        'p_statements' => ['P210', 'P305'],
    ]);

    $item = Item::where('item_code', 'CHM-GHS01')->firstOrFail();
    $response->assertRedirect(route('items.index'));
    expect($item->ghs_codes)->toBe(['GHS02', 'GHS07']);
    expect($item->h_statements)->toBe(['H225', 'H319']);
    expect($item->p_statements)->toBe(['P210', 'P305']);
});

test('an invalid GHS pictogram code is rejected', function () {
    $manager = labManagerUser();
    $item = makeItem();

    $this->actingAs($manager)->put(route('items.update', $item), [
        'item_code' => $item->item_code,
        'category_id' => $item->category_id,
        'name_th' => $item->name_th,
        'base_unit_id' => $item->base_unit_id,
        'ghs_codes' => ['GHS99'],
    ])->assertSessionHasErrors('ghs_codes.0');
});

test('an invalid H-statement code is rejected', function () {
    $manager = labManagerUser();
    $item = makeItem();

    $this->actingAs($manager)->put(route('items.update', $item), [
        'item_code' => $item->item_code,
        'category_id' => $item->category_id,
        'name_th' => $item->name_th,
        'base_unit_id' => $item->base_unit_id,
        'h_statements' => ['H999'],
    ])->assertSessionHasErrors('h_statements.0');
});

test('an invalid P-statement code is rejected', function () {
    $manager = labManagerUser();
    $item = makeItem();

    $this->actingAs($manager)->put(route('items.update', $item), [
        'item_code' => $item->item_code,
        'category_id' => $item->category_id,
        'name_th' => $item->name_th,
        'base_unit_id' => $item->base_unit_id,
        'p_statements' => ['P999'],
    ])->assertSessionHasErrors('p_statements.0');
});

test('the item detail page shows GHS pictograms and H/P statement text to a view-only user', function () {
    $scientist = scientistUser();
    $item = makeItem([
        'ghs_codes' => ['GHS06'],
        'h_statements' => ['H301'],
        'p_statements' => ['P310'],
    ]);

    $this->actingAs($scientist)
        ->get(route('items.show', $item))
        ->assertOk()
        ->assertSee($item->name_th)
        ->assertSee('GHS06')
        ->assertSee('Toxic if swallowed')
        ->assertSee('Immediately call a POISON CENTER or doctor/physician');
});

test('a user without item.view gets 403 on the item detail page', function () {
    $user = \App\Models\User::factory()->create();
    $item = makeItem();

    $this->actingAs($user)->get(route('items.show', $item))->assertStatus(403);
});

test('SCIENTIST (item.view only) cannot open the edit page but can open the detail page', function () {
    $scientist = scientistUser();
    $item = makeItem();

    $this->actingAs($scientist)->get(route('items.edit', $item))->assertStatus(403);
    $this->actingAs($scientist)->get(route('items.show', $item))->assertOk();
});
