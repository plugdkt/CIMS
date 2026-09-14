<?php

use App\Models\Container;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->roles()->attach(\App\Models\Role::where('code', 'ADMIN')->first());

    $this->scientist = User::factory()->create();
    $this->scientist->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->first());

    $this->student = User::factory()->create(['person_type' => 'STUDENT']);
    $this->student->roles()->attach(\App\Models\Role::where('code', 'STUDENT')->first());

    $this->unitG = Unit::where('code', 'g')->first() ?? Unit::create(['name_th' => 'กรัม', 'code' => 'g', 'sort_order' => 1]);
    $this->location = Location::create([
        'name' => 'ตู้เก็บสารเคมี A1',
        'code' => 'CAB-A1',
        'type' => 'CABINET',
    ]);

    $this->item = Item::create([
        'item_code' => 'CHM-TEST-01',
        'name_th' => 'แป้งข้าวโพดสำหรับทดสอบ',
        'category_id' => \App\Models\ItemCategory::first()->id ?? 1,
        'base_unit_id' => $this->unitG->id,
        'package_unit_id' => $this->unitG->id,
        'package_size' => '1.000000',
        'storage_class' => 'OTHER',
        'reorder_point_base' => '0.000000',
        'is_active' => true,
    ]);
});

test('unauthenticated users are redirected from stock-in', function () {
    $this->get(route('stock-in.create'))->assertRedirect(route('login'));
    $this->post(route('stock-in.store'), [])->assertRedirect(route('login'));
});

test('students cannot perform stock-in', function () {
    $this->actingAs($this->student)
        ->post(route('stock-in.store'), [
            'item_id' => $this->item->id,
            'tracking_type' => 'BULK',
            'qty' => '500',
            'unit_id' => $this->unitG->id,
            'location_id' => $this->location->id,
        ])
        ->assertForbidden();
});

test('scientist can perform bulk stock-in successfully', function () {
    $response = $this->actingAs($this->scientist)
        ->post(route('stock-in.store'), [
            'item_id' => $this->item->id,
            'tracking_type' => 'BULK',
            'qty' => '500',
            'unit_id' => $this->unitG->id,
            'location_id' => $this->location->id,
            'remark' => 'เบิกจากคลังใหญ่',
        ]);

    $response->assertRedirect(route('items.show', $this->item));
    $response->assertSessionHas('status');

    $container = Container::where('item_id', $this->item->id)->first();
    expect($container)->not->toBeNull();
    expect($container->status)->toBe('IN_USE');
    expect((float) $container->remaining_qty_base)->toEqual(500.0);

    $ledger = StockLedger::where('item_id', $this->item->id)->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->txn_type)->toBe('RECEIVE');
    expect($ledger->ref_type)->toBe('WORKING_STOCK');
    expect((float) $ledger->qty_in_base)->toEqual(500.0);
    expect((float) $ledger->balance_base)->toEqual(500.0);
});

test('scientist can perform container stock-in with multiple containers', function () {
    $response = $this->actingAs($this->scientist)
        ->post(route('stock-in.store'), [
            'item_id' => $this->item->id,
            'tracking_type' => 'CONTAINER',
            'container_count' => 2,
            'qty_per_container' => '250',
            'unit_id' => $this->unitG->id,
            'location_id' => $this->location->id,
            'lot_no' => 'LOT-WS-99',
            'remark' => 'รับเข้า 2 ขวด',
        ]);

    $response->assertRedirect(route('items.show', $this->item));
    $response->assertSessionHas('label_container_ids');

    $containers = Container::where('item_id', $this->item->id)->get();
    expect($containers)->toHaveCount(2);
    foreach ($containers as $c) {
        expect($c->status)->toBe('SEALED');
        expect((float) $c->remaining_qty_base)->toEqual(250.0);
        expect($c->barcode)->toStartWith('WS-');
        expect($c->lot_no)->toBe('LOT-WS-99');
    }

    $ledgerRows = StockLedger::where('item_id', $this->item->id)->get();
    expect($ledgerRows)->toHaveCount(2);
    expect((float) $ledgerRows->last()->balance_base)->toEqual(500.0);
});

test('can render PDF barcode labels for created containers', function () {
    $c1 = Container::create([
        'barcode' => 'WS-TEST-001',
        'item_id' => $this->item->id,
        'location_id' => $this->location->id,
        'initial_qty_base' => '250.000000',
        'remaining_qty_base' => '250.000000',
        'received_at' => now(),
        'status' => 'SEALED',
    ]);

    $response = $this->actingAs($this->scientist)
        ->get(route('stock-in.labels', ['size' => '40x25', 'ids' => (string) $c1->id]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});
