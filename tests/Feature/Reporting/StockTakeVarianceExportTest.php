<?php

use App\Domain\Inventory\Services\StockTakeService;
use App\Domain\Reporting\Exports\StockTakeVarianceExport;
use App\Domain\Reporting\Services\StockTakeVariancePdfService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('§7.8 stock take variance lists every line of the given round, counted or not', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $user = User::factory()->create();
    $counted = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '100']);
    $uncounted = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'SEALED', 'remaining_qty_base' => '50']);

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);
    $line = $stockTake->lines->firstWhere('container_id', $counted->id);
    app(StockTakeService::class)->recordCount($line, '95.000000', $user, 'พบขวดรั่วเล็กน้อย');

    $rows = (new StockTakeVarianceExport($stockTake->fresh()))->collection();

    expect($rows)->toHaveCount(2);
    $countedRow = collect($rows)->firstWhere(3, '95.000000');
    expect($countedRow)->not->toBeNull();
    expect($countedRow[4])->toBe('-5.000000');
    $uncountedRow = collect($rows)->first(fn ($r) => $r[1] === $uncounted->barcode);
    expect($uncountedRow[3])->toBe('ยังไม่ได้นับ');
});

test('StockTakeVariancePdfService renders a non-empty PDF', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $user = User::factory()->create();
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '100']);

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);

    $pdf = app(StockTakeVariancePdfService::class)->render($stockTake->fresh());

    expect($pdf)->toStartWith('%PDF');
});
