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
    $this->lab = makeLab();

    $this->auditor = User::factory()->create(['lab_id' => $this->lab->id]);
    $this->auditor->roles()->attach(\App\Models\Role::where('code', 'AUDITOR')->first());
    $this->location = Location::create([
        'name' => 'ตู้เก็บสารเคมี A1',
        'code' => 'CAB-A1',
        'level_type' => 'CABINET',
        'lab_id' => $this->lab->id,
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

test('scientists cannot perform stock-in (reserved for warehouse managers)', function () {
    $this->actingAs($this->scientist)->get(route('stock-in.create'))->assertForbidden();

    $this->actingAs($this->scientist)
        ->post(route('stock-in.store'), [
            'item_id' => $this->item->id,
            'tracking_type' => 'BULK',
            'qty' => '500',
            'unit_id' => $this->unitG->id,
            'location_id' => $this->location->id,
        ])
        ->assertForbidden();
});

test('warehouse manager (AUDITOR) can perform bulk stock-in successfully', function () {
    $response = $this->actingAs($this->auditor)
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

test('warehouse manager (AUDITOR) can perform container stock-in with multiple containers', function () {
    $response = $this->actingAs($this->auditor)
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

    $response = $this->actingAs($this->auditor)
        ->get(route('stock-in.labels', ['size' => '40x25', 'ids' => (string) $c1->id]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('stock-in automatically sets base_unit_id on item if it was not specified', function () {
    $itemWithoutUnit = Item::create([
        'item_code' => 'CHM-NO-UNIT',
        'name_th' => 'สารเคมีใหม่ยังไม่มีหน่วยฐาน',
        'category_id' => \App\Models\ItemCategory::first()->id ?? 1,
        'base_unit_id' => null,
        'package_size' => null,
        'storage_class' => 'OTHER',
        'reorder_point_base' => '0.000000',
        'is_active' => true,
    ]);

    expect($itemWithoutUnit->base_unit_id)->toBeNull();

    $response = $this->actingAs($this->auditor)
        ->post(route('stock-in.store'), [
            'item_id' => $itemWithoutUnit->id,
            'tracking_type' => 'BULK',
            'qty' => '100',
            'unit_id' => $this->unitG->id,
            'location_id' => $this->location->id,
            'remark' => 'รับเข้าสารใหม่',
        ]);

    $response->assertRedirect(route('items.show', $itemWithoutUnit));
    expect($itemWithoutUnit->fresh()->base_unit_id)->toBe($this->unitG->id);
});

test('user-reported 2026-09-22: the first real stock-in adopts the unit it was received in, overriding a guessed catalog unit', function () {
    $litre = Unit::where('code', 'L')->firstOrFail();
    $millilitre = Unit::where('code', 'mL')->firstOrFail();

    // What the chemical catalog import produces: a base unit parsed out of a product name.
    $imported = Item::create([
        'item_code' => 'AS-UNIT-01',
        'name_th' => 'สารทดสอบหน่วย 1 L /ขวด',
        'category_id' => \App\Models\ItemCategory::first()->id ?? 1,
        'base_unit_id' => $litre->id,
        'storage_class' => 'OTHER',
        'reorder_point_base' => '0.000000',
        'is_active' => true,
    ]);

    $this->actingAs($this->auditor)
        ->post(route('stock-in.store'), [
            'item_id' => $imported->id,
            'tracking_type' => 'BULK',
            'qty' => '500',
            'unit_id' => $millilitre->id,
            'location_id' => $this->location->id,
        ])->assertRedirect(route('items.show', $imported));

    expect($imported->fresh()->base_unit_id)->toBe($millilitre->id);

    // 500 mL stays 500, not 0.5 — the stored value is in the item's own base unit.
    $ledger = StockLedger::where('item_id', $imported->id)->firstOrFail();
    expect((float) $ledger->qty_in_base)->toEqual(500.0);
    expect((float) $ledger->balance_base)->toEqual(500.0);
});

test('user-reported 2026-09-22: adopting a new base unit rescales the reorder point so it keeps its meaning', function () {
    $litre = Unit::where('code', 'L')->firstOrFail();
    $millilitre = Unit::where('code', 'mL')->firstOrFail();

    $imported = Item::create([
        'item_code' => 'AS-UNIT-02',
        'name_th' => 'สารทดสอบจุดสั่งซื้อ',
        'category_id' => \App\Models\ItemCategory::first()->id ?? 1,
        'base_unit_id' => $litre->id,
        'storage_class' => 'OTHER',
        'reorder_point_base' => '2.000000', // 2 L
        'is_active' => true,
    ]);

    $this->actingAs($this->auditor)
        ->post(route('stock-in.store'), [
            'item_id' => $imported->id,
            'tracking_type' => 'BULK',
            'qty' => '500',
            'unit_id' => $millilitre->id,
            'location_id' => $this->location->id,
        ])->assertRedirect();

    expect((float) $imported->fresh()->reorder_point_base)->toEqual(2000.0); // 2 L === 2000 mL
});

test('an item that already has ledger history keeps its base unit — stock_ledger is append-only', function () {
    $kilogram = Unit::where('code', 'kg')->firstOrFail();
    $originalBaseUnitId = $this->item->base_unit_id;

    $payload = [
        'item_id' => $this->item->id,
        'tracking_type' => 'BULK',
        'qty' => '100',
        'unit_id' => $this->unitG->id,
        'location_id' => $this->location->id,
    ];

    $this->actingAs($this->auditor)->post(route('stock-in.store'), $payload)->assertRedirect();

    // A second receipt in a different unit must NOT reinterpret the rows already written.
    $this->actingAs($this->auditor)->post(route('stock-in.store'), array_merge($payload, [
        'qty' => '50',
        'unit_id' => $kilogram->id,
    ]));

    expect($this->item->fresh()->base_unit_id)->toBe($originalBaseUnitId);
});

test('branch scoping: a LAB_MANAGER only sees their own branch\'s locations on the stock-in page', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $ownLocation = makeLocationForLab($lab);
    $otherLocation = makeLocationForLab($otherLab);
    $manager = labManagerUser(['lab_id' => $lab->id]);

    $this->actingAs($manager)->get(route('stock-in.create'))
        ->assertOk()
        ->assertSee($ownLocation->code)
        ->assertDontSee($otherLocation->code);
});

test('branch scoping: a LAB_MANAGER cannot stock-in into a location outside their own branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $otherLocation = makeLocationForLab($otherLab);
    $manager = labManagerUser(['lab_id' => $lab->id]);

    $this->actingAs($manager)->post(route('stock-in.store'), [
        'item_id' => $this->item->id,
        'tracking_type' => 'BULK',
        'qty' => '500',
        'unit_id' => $this->unitG->id,
        'location_id' => $otherLocation->id,
    ])->assertSessionHasErrors('location_id');

    expect(Container::where('item_id', $this->item->id)->count())->toBe(0);
});

test('branch scoping: a LAB_MANAGER can stock-in into their own branch\'s location', function () {
    $lab = makeLab();
    $ownLocation = makeLocationForLab($lab);
    $manager = labManagerUser(['lab_id' => $lab->id]);

    $this->actingAs($manager)->post(route('stock-in.store'), [
        'item_id' => $this->item->id,
        'tracking_type' => 'BULK',
        'qty' => '500',
        'unit_id' => $this->unitG->id,
        'location_id' => $ownLocation->id,
    ])->assertRedirect(route('items.show', $this->item));

    expect(Container::where('item_id', $this->item->id)->where('location_id', $ownLocation->id)->exists())->toBeTrue();
});
