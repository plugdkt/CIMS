<?php

use App\Models\Container;
use App\Models\Item;
use App\Models\Location;
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

// The direct stock-in form was removed 2026-09-30 (working stock now only comes from IMS —
// see ImsTest.php). Only the shared barcode-label route is left to cover here.

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
