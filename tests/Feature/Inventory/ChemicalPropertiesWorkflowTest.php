<?php

declare(strict_types=1);

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Reporting\Services\Fr01PdfService;
use App\Domain\Reporting\Services\Fr03PdfService;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\RequisitionItem;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->lab = makeLab();

    $this->scientist = User::factory()->create(['lab_id' => $this->lab->id]);
    $this->scientist->roles()->attach(Role::where('code', 'SCIENTIST')->first());

    $this->student = User::factory()->create(['person_type' => 'STUDENT', 'lab_id' => $this->lab->id]);
    $this->student->roles()->attach(Role::where('code', 'STUDENT')->first());

    $this->unit = Unit::where('code', 'mL')->first() ?? Unit::create(['name_th' => 'มิลลิลิตร', 'code' => 'mL', 'sort_order' => 1]);
    $category = ItemCategory::first() ?? ItemCategory::create(['code' => 'CHEMICAL', 'name_th' => 'สารเคมี', 'name_en' => 'Chemical']);

    $this->item = Item::create([
        'item_code' => 'CHM-TEST-WF-01',
        'name_th' => 'เอทานอลทดสอบ',
        'name_en' => 'Ethanol Test',
        'category_id' => $category->id,
        'base_unit_id' => $this->unit->id,
        'package_unit_id' => $this->unit->id,
        'package_size' => '500.000000',
        'storage_class' => 'OTHER',
        'grade' => 'AR',
        'physical_state' => 'liquid',
        'reorder_point_base' => '0.000000',
        'is_active' => true,
    ]);

    $this->location = Location::create([
        'name' => 'ตู้เก็บสารเคมีทดสอบ',
        'code' => 'CAB-TEST-01',
        'level_type' => 'CABINET',
        'lab_id' => $this->lab->id,
    ]);
});

test('stock-in create view includes grade and physical_state in items JSON payload', function () {
    $response = $this->actingAs($this->scientist)->get(route('stock-in.create'));

    $response->assertOk();
    $response->assertSee('เอทานอลทดสอบ');
    $response->assertSee('AR');
    $response->assertSee('liquid');
});

test('requisition show view displays item grade and physical_state', function () {
    $requisition = makeRequisition($this->student);

    RequisitionItem::create([
        'requisition_id' => $requisition->id,
        'line_no' => 1,
        'item_id' => $this->item->id,
        'qty_requested' => '100.000000',
        'qty_requested_base' => '100.000000',
        'qty_issued_base' => '0.000000',
        'unit_id' => $this->unit->id,
    ]);

    $response = $this->actingAs($this->student)->get(route('requisitions.show', $requisition));

    $response->assertOk();
    $response->assertSee('เอทานอลทดสอบ');
    $response->assertSee('AR');
    $response->assertSee('ของเหลว (Liquid)');
});

test('F-01 PDF renders item with grade and physical_state', function () {
    $requisition = makeRequisition($this->student);

    RequisitionItem::create([
        'requisition_id' => $requisition->id,
        'line_no' => 1,
        'item_id' => $this->item->id,
        'qty_requested' => '50.000000',
        'qty_requested_base' => '50.000000',
        'qty_issued_base' => '0.000000',
        'unit_id' => $this->unit->id,
    ]);

    $pdf = app(Fr01PdfService::class)->render($requisition);

    expect($pdf)->toStartWith('%PDF');
});

test('F-03 PDF renders with physical_state in header metadata', function () {
    $pdf = app(Fr03PdfService::class)->render($this->item, new LedgerFilter(), $this->unit);

    expect($pdf)->toStartWith('%PDF');
});
