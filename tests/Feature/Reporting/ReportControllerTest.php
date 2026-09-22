<?php

use App\Domain\Inventory\Services\StockTakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without report.view gets 403 on the reports index', function () {
    $student = studentUser();

    $this->actingAs($student)->get(route('reports.index'))->assertStatus(403);
});

test('a warehouse manager (report.view) can open the reports index', function () {
    $manager = auditorUser();

    $this->actingAs($manager)->get(route('reports.index'))->assertOk()->assertSee(__('reports.usage_summary_title'));
});

test('every Excel export route is reachable by a warehouse manager and returns a file', function () {
    $manager = auditorUser();

    foreach ([
        'reports.item-stock-summary.excel',
        'reports.usage-summary.excel',
        'reports.expiring-stock.excel',
        'reports.below-reorder-point.excel',
        'reports.dead-stock.excel',
        'reports.controlled-substances.excel',
    ] as $routeName) {
        $this->actingAs($manager)->get(route($routeName))->assertOk();
    }
});

test('the controlled substances PDF route returns a PDF', function () {
    $manager = auditorUser();

    $response = $this->actingAs($manager)->get(route('reports.controlled-substances.pdf'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('the stock take variance routes are reachable and gated on report.view', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $user = \App\Models\User::factory()->create();
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);
    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);

    // A branch manager may only open a stock take from their own branch (authorizeStockTakeOwnLab).
    $manager = auditorUser(['lab_id' => $lab->id]);
    $this->actingAs($manager)->get(route('reports.stock-take.excel', $stockTake))->assertOk();

    $pdfResponse = $this->actingAs($manager)->get(route('reports.stock-take.pdf', $stockTake));
    $pdfResponse->assertOk();
    $pdfResponse->assertHeader('Content-Type', 'application/pdf');

    $student = studentUser();
    $this->actingAs($student)->get(route('reports.stock-take.excel', $stockTake))->assertStatus(403);
});
