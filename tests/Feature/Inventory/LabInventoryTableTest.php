<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest is redirected to login', function () {
    $this->get(route('stock-in.index'))->assertRedirect(route('login'));
});

test('a user without item.view gets 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('stock-in.index'))->assertStatus(403);
});

test('a SCIENTIST sees SEALED/IN_USE containers with stock in their own lab', function () {
    $lab = makeLab();
    $scientist = User::factory()->create(['lab_id' => $lab->id]);
    $scientist->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());
    $location = makeLocationForLab($lab);
    $item = makeItem(['name_th' => 'เอทานอลทดสอบคลัง']);

    $visible = makeContainer([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'status' => 'SEALED',
        'remaining_qty_base' => '50.000000',
    ]);

    $this->actingAs($scientist)->get(route('stock-in.index'))
        ->assertOk()
        ->assertSee($item->name_th)
        ->assertSee($visible->barcode);
});

test('an EMPTY container or one with zero remaining stock is excluded', function () {
    $lab = makeLab();
    $scientist = User::factory()->create(['lab_id' => $lab->id]);
    $scientist->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());
    $location = makeLocationForLab($lab);
    $item = makeItem();

    $empty = makeContainer([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'status' => 'EMPTY',
        'remaining_qty_base' => '0.000000',
    ]);
    $depleted = makeContainer([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'status' => 'IN_USE',
        'remaining_qty_base' => '0.000000',
    ]);

    $this->actingAs($scientist)->get(route('stock-in.index'))
        ->assertOk()
        ->assertDontSee($empty->barcode)
        ->assertDontSee($depleted->barcode);
});

test('branch scoping: a SCIENTIST never sees another lab\'s containers', function () {
    $ownLab = makeLab();
    $otherLab = makeLab();
    $scientist = User::factory()->create(['lab_id' => $ownLab->id]);
    $scientist->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());

    $ownLocation = makeLocationForLab($ownLab);
    $otherLocation = makeLocationForLab($otherLab);

    $ownContainer = makeContainer(['location_id' => $ownLocation->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $otherContainer = makeContainer(['location_id' => $otherLocation->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    $this->actingAs($scientist)->get(route('stock-in.index'))
        ->assertOk()
        ->assertSee($ownContainer->barcode)
        ->assertDontSee($otherContainer->barcode);
});

test('a user with no lab assigned sees an empty list, never every lab\'s stock', function () {
    $lab = makeLab();
    $unassigned = User::factory()->create(['lab_id' => null]);
    $unassigned->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());

    $location = makeLocationForLab($lab);
    $container = makeContainer(['location_id' => $location->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    $this->actingAs($unassigned)->get(route('stock-in.index'))
        ->assertOk()
        ->assertDontSee($container->barcode)
        ->assertSee(__('stock.no_results'));
});

test('ADMIN can pick any lab via the lab filter and is not forced into one branch', function () {
    $labA = makeLab();
    $labB = makeLab();
    $admin = User::factory()->create();
    $admin->roles()->attach(\App\Models\Role::where('code', 'ADMIN')->firstOrFail());

    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);
    $containerA = makeContainer(['location_id' => $locationA->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $containerB = makeContainer(['location_id' => $locationB->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    // No lab filter chosen — ADMIN sees every lab's stock.
    $this->actingAs($admin)->get(route('stock-in.index'))
        ->assertOk()
        ->assertSee($containerA->barcode)
        ->assertSee($containerB->barcode);

    // Filtering to one lab narrows the list.
    $this->actingAs($admin)->get(route('stock-in.index', ['labId' => $labA->id]))
        ->assertOk()
        ->assertSee($containerA->barcode)
        ->assertDontSee($containerB->barcode);
});

test('searching by barcode or item name filters the list', function () {
    $lab = makeLab();
    $scientist = User::factory()->create(['lab_id' => $lab->id]);
    $scientist->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());
    $location = makeLocationForLab($lab);

    $matching = makeContainer([
        'item_id' => makeItem(['name_th' => 'โซเดียมคลอไรด์'])->id,
        'location_id' => $location->id,
        'status' => 'SEALED',
        'remaining_qty_base' => '10.000000',
    ]);
    $other = makeContainer([
        'item_id' => makeItem(['name_th' => 'เอทานอล'])->id,
        'location_id' => $location->id,
        'status' => 'SEALED',
        'remaining_qty_base' => '10.000000',
    ]);

    $this->actingAs($scientist)->get(route('stock-in.index', ['search' => 'โซเดียมคลอไรด์']))
        ->assertOk()
        ->assertSee($matching->barcode)
        ->assertDontSee($other->barcode);
});

test('the item detail page shows only containers of that item in the viewer\'s own lab', function () {
    $ownLab = makeLab();
    $otherLab = makeLab();
    $scientist = User::factory()->create(['lab_id' => $ownLab->id]);
    $scientist->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());

    $item = makeItem();
    $ownLocation = makeLocationForLab($ownLab);
    $otherLocation = makeLocationForLab($otherLab);

    $ownContainer = makeContainer(['item_id' => $item->id, 'location_id' => $ownLocation->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $otherContainer = makeContainer(['item_id' => $item->id, 'location_id' => $otherLocation->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    // A different item's container in the same lab must never show up here.
    $otherItemContainer = makeContainer(['location_id' => $ownLocation->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    $this->actingAs($scientist)->get(route('items.show', $item))
        ->assertOk()
        ->assertSee($ownContainer->barcode)
        ->assertDontSee($otherContainer->barcode)
        ->assertDontSee($otherItemContainer->barcode);
});

test('an ADMIN sees an item\'s containers across every lab on the item detail page', function () {
    $labA = makeLab();
    $labB = makeLab();
    $admin = User::factory()->create();
    $admin->roles()->attach(\App\Models\Role::where('code', 'ADMIN')->firstOrFail());

    $item = makeItem();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);
    $containerA = makeContainer(['item_id' => $item->id, 'location_id' => $locationA->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $containerB = makeContainer(['item_id' => $item->id, 'location_id' => $locationB->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    $this->actingAs($admin)->get(route('items.show', $item))
        ->assertOk()
        ->assertSee($containerA->barcode)
        ->assertSee($containerB->barcode);
});
