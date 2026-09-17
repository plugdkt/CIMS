<?php

use App\Domain\Inventory\Services\StockTakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without report.view gets 403 on the reports index', function () {
    $student = studentUser();

    $this->actingAs($student)->get(route('reports.index'))->assertStatus(403);
});

test('a SCIENTIST (report.view) can open the reports index', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('reports.index'))->assertOk()->assertSee(__('reports.usage_summary_title'));
});

test('every Excel export route is reachable by a SCIENTIST and returns a file', function () {
    $scientist = scientistUser();

    foreach ([
        'reports.item-stock-summary.excel',
        'reports.usage-summary.excel',
        'reports.expiring-stock.excel',
        'reports.below-reorder-point.excel',
        'reports.dead-stock.excel',
        'reports.controlled-substances.excel',
    ] as $routeName) {
        $this->actingAs($scientist)->get(route($routeName))->assertOk();
    }
});

test('the controlled substances PDF route returns a PDF', function () {
    $scientist = scientistUser();

    $response = $this->actingAs($scientist)->get(route('reports.controlled-substances.pdf'));

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

    $scientist = scientistUser();
    $this->actingAs($scientist)->get(route('reports.stock-take.excel', $stockTake))->assertOk();

    $pdfResponse = $this->actingAs($scientist)->get(route('reports.stock-take.pdf', $stockTake));
    $pdfResponse->assertOk();
    $pdfResponse->assertHeader('Content-Type', 'application/pdf');

    $student = studentUser();
    $this->actingAs($student)->get(route('reports.stock-take.excel', $stockTake))->assertStatus(403);
});
