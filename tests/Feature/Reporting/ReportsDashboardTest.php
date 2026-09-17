<?php

use App\Domain\Inventory\Services\StockTakeService;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// issueOneLine() now lives in tests/Pest.php (moved there from UsageSummaryExportTest.php,
// which used to declare it unguarded — a second unguarded copy here would have fataled
// the whole suite with "Cannot redeclare", the same class of bug as
// docs/qa_finding_2026-09-17_test_suite_fatal_redeclare.md).

test('the usage summary tab live-filters by requester name', function () {
    $staff = staffUser(['full_name' => 'สมชาย ใจดี']);
    issueOneLine(approvedRequisition($staff)->fresh(['items']));
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'usage_summary']))
        ->assertOk()
        ->assertSee('สมชาย ใจดี');

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'usage_summary', 'usageRequesterName' => 'ไม่มีตัวตน']))
        ->assertOk()
        ->assertDontSee('สมชาย ใจดี');
});

test('the expiring stock tab live-filters by date range', function () {
    $item = makeItem(['name_th' => 'สารทดสอบใกล้หมดอายุ']);
    makeContainer([
        'item_id' => $item->id,
        'status' => 'SEALED',
        'remaining_qty_base' => '10.000000',
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'expiring_stock']))
        ->assertOk()
        ->assertSee('สารทดสอบใกล้หมดอายุ');

    $this->actingAs($scientist)->get(route('reports.index', [
        'tab' => 'expiring_stock',
        'expiringFrom' => now()->addDays(30)->toDateString(),
    ]))->assertOk()->assertDontSee('สารทดสอบใกล้หมดอายุ');
});

test('the below-reorder tab live-filters by lab, and a LAB_MANAGER never sees the lab picker', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);

    $itemA = makeItem(['name_th' => 'สารสาขา A', 'reorder_point_base' => '50.000000']);
    makeContainer(['item_id' => $itemA->id, 'location_id' => $locationA->id, 'status' => 'SEALED', 'remaining_qty_base' => '1.000000']);
    StockLedger::create([
        'item_id' => $itemA->id, 'txn_date' => now()->toDateString(), 'txn_type' => 'OPENING',
        'qty_in_base' => '0', 'qty_out_base' => '0', 'balance_base' => '1.000000',
        'display_unit_id' => Unit::where('code', 'g')->value('id'), 'created_by' => User::factory()->create()->id,
        'created_at' => now(), 'prev_row_hash' => null, 'row_hash' => str_repeat('a', 64),
    ]);

    $itemB = makeItem(['name_th' => 'สารสาขา B', 'reorder_point_base' => '50.000000']);
    makeContainer(['item_id' => $itemB->id, 'location_id' => $locationB->id, 'status' => 'SEALED', 'remaining_qty_base' => '1.000000']);
    StockLedger::create([
        'item_id' => $itemB->id, 'txn_date' => now()->toDateString(), 'txn_type' => 'OPENING',
        'qty_in_base' => '0', 'qty_out_base' => '0', 'balance_base' => '1.000000',
        'display_unit_id' => Unit::where('code', 'g')->value('id'), 'created_by' => User::factory()->create()->id,
        'created_at' => now(), 'prev_row_hash' => null, 'row_hash' => str_repeat('b', 64),
    ]);

    $scientist = scientistUser();
    $response = $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'below_reorder']));
    $response->assertOk()->assertSee('สารสาขา A')->assertSee('สารสาขา B');
    // The summary chart actually rendered real SVG bars, not just the "no data" placeholder.
    $response->assertSee('<svg', false)->assertDontSee(__('reports.chart_no_data'));

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'below_reorder', 'labId' => $labA->id]))
        ->assertOk()->assertSee('สารสาขา A')->assertDontSee('สารสาขา B');

    $manager = labManagerUser(['lab_id' => $labA->id]);
    $response = $this->actingAs($manager)->get(route('reports.index', ['tab' => 'below_reorder']));
    $response->assertOk()->assertSee('สารสาขา A')->assertDontSee('สารสาขา B');
    $response->assertSee(__('reports.restricted_to_own_lab'));

    // A LAB_MANAGER can't widen their own view via the query string either.
    $this->actingAs($manager)->get(route('reports.index', ['tab' => 'below_reorder', 'labId' => $labB->id]))
        ->assertOk()->assertSee('สารสาขา A')->assertDontSee('สารสาขา B');
});

test('the dead stock tab lists a container with no recent movement', function () {
    $item = makeItem(['name_th' => 'สารค้างสต็อกทดสอบ']);
    $container = makeContainer(['item_id' => $item->id, 'status' => 'SEALED', 'remaining_qty_base' => '5.000000']);
    StockLedger::create([
        'item_id' => $item->id, 'container_id' => $container->id, 'txn_date' => now()->subMonths(13)->toDateString(),
        'txn_type' => 'OPENING', 'qty_in_base' => '5', 'qty_out_base' => '0', 'balance_base' => '5.000000',
        'display_unit_id' => Unit::where('code', 'g')->value('id'), 'created_by' => User::factory()->create()->id,
        'created_at' => now()->subMonths(13), 'prev_row_hash' => null, 'row_hash' => str_repeat('c', 64),
    ]);
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'dead_stock']))
        ->assertOk()
        ->assertSee('สารค้างสต็อกทดสอบ');
});

test('the controlled substances tab only lists movements for controlled items', function () {
    $controlled = makeItem(['name_th' => 'สารควบคุมทดสอบ', 'is_controlled' => true, 'control_class' => 'ประเภท 1']);
    $ordinary = makeItem(['name_th' => 'สารทั่วไปทดสอบ', 'is_controlled' => false]);
    $creator = User::factory()->create();
    foreach ([$controlled, $ordinary] as $i => $item) {
        StockLedger::create([
            'item_id' => $item->id, 'txn_date' => now()->toDateString(), 'txn_type' => 'OPENING',
            'qty_in_base' => '10', 'qty_out_base' => '0', 'balance_base' => '10.000000',
            'display_unit_id' => Unit::where('code', 'g')->value('id'), 'created_by' => $creator->id,
            'created_at' => now(), 'prev_row_hash' => null, 'row_hash' => str_repeat((string) $i, 64),
        ]);
    }
    $scientist = scientistUser();

    $response = $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'controlled_substances']));
    $response->assertOk()->assertSee('สารควบคุมทดสอบ')->assertDontSee('สารทั่วไปทดสอบ');
});

test('the stock take variance tab shows nothing until a round is selected, then shows its lines', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem(['name_th' => 'สารตรวจนับทดสอบ']);
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10.000000']);
    $user = User::factory()->create();
    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'stock_take_variance']))
        ->assertOk()
        ->assertSee(__('reports.select_stock_take_first'))
        ->assertDontSee('สารตรวจนับทดสอบ');

    $this->actingAs($scientist)->get(route('reports.index', ['tab' => 'stock_take_variance', 'stockTakeUlid' => $stockTake->ulid]))
        ->assertOk()
        ->assertSee('สารตรวจนับทดสอบ');
});

test('a LAB_MANAGER cannot view another branch\'s stock take by guessing its ulid', function () {
    $ownLab = makeLab();
    $otherLab = makeLab();
    $user = User::factory()->create();
    $otherStockTake = app(StockTakeService::class)->create($otherLab, now()->toDateString(), $user);
    $manager = labManagerUser(['lab_id' => $ownLab->id]);

    $this->actingAs($manager)->get(route('reports.index', [
        'tab' => 'stock_take_variance', 'stockTakeUlid' => $otherStockTake->ulid,
    ]))->assertOk()->assertSee(__('reports.select_stock_take_first'));
});

test('a user without report.view gets 403 regardless of which tab is requested', function () {
    $student = studentUser();

    $this->actingAs($student)->get(route('reports.index', ['tab' => 'dead_stock']))->assertStatus(403);
});
