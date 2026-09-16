<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidStockTakeException;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Inventory\Services\StockTakeService;
use App\Models\Location;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('makeLocationForLab')) {
    function makeLocationForLab(\App\Models\Lab $lab): Location
    {
        return Location::create([
            'code' => 'BLD-'.fake()->unique()->numerify('####'),
            'name' => 'อาคารทดสอบ',
            'level_type' => 'BUILDING',
            'lab_id' => $lab->id,
        ]);
    }
}

test('FR-ST-02: creating a round generates one line per active container in the lab, excluding EMPTY/DISPOSED', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    $inUse = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '30']);
    $sealed = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'SEALED', 'remaining_qty_base' => '50']);
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'EMPTY', 'remaining_qty_base' => '0']);
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'DISPOSED', 'remaining_qty_base' => '0']);
    makeContainer(['item_id' => $item->id, 'location_id' => null, 'status' => 'IN_USE', 'remaining_qty_base' => '10']); // no location, no lab

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);

    expect($stockTake->status)->toBe('COUNTING');
    expect($stockTake->doc_no)->toStartWith('STK-');
    expect($stockTake->lines)->toHaveCount(2);
    $containerIds = $stockTake->lines->pluck('container_id')->all();
    expect($containerIds)->toContain($inUse->id, $sealed->id);
});

test('FR-ST-03: recording a count computes diff_base and stamps the counter', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '100']);

    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);
    $line = $stockTake->lines->first();

    $counter = User::factory()->create();
    $updated = app(StockTakeService::class)->recordCount($line, '95.000000', $counter, 'พบขวดรั่วเล็กน้อย');

    expect($updated->counted_qty_base)->toBe('95.000000');
    expect($updated->diff_base)->toBe('-5.000000');
    expect($updated->counted_by)->toBe($counter->id);
});

test('submitting for approval requires every line to be counted first', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $user = User::factory()->create();
    makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '100']);
    $stockTake = app(StockTakeService::class)->create($lab, now()->toDateString(), $user);

    expect(fn () => app(StockTakeService::class)->submitForApproval($stockTake))
        ->toThrow(InvalidStockTakeException::class);

    app(StockTakeService::class)->recordCount($stockTake->lines->first(), '100.000000', $user);
    $submitted = app(StockTakeService::class)->submitForApproval($stockTake->fresh());

    expect($submitted->status)->toBe('PENDING_APPROVAL');
});

test('FT-07: LAB_MANAGER approval writes ADJUST_OUT for a negative difference', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $counter = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '0']);
    app(LedgerService::class)->receive($container->id, '100.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $counter->id));

    $service = app(StockTakeService::class);
    $stockTake = $service->create($lab, now()->toDateString(), $counter);
    $line = $stockTake->lines()->where('container_id', $container->fresh()->id)->firstOrFail();
    $service->recordCount($line, '88.000000', $counter, 'นับพบของหายบางส่วน');
    $service->submitForApproval($stockTake->fresh());

    $labManager = labManagerUser();
    $approved = $service->approve($stockTake->fresh(), $labManager);

    expect($approved->status)->toBe('APPROVED');
    expect($container->fresh()->remaining_qty_base)->toBe('88.000000');
    $adjustRow = StockLedger::where('item_id', $item->id)->where('txn_type', 'ADJUST_OUT')->first();
    expect($adjustRow)->not->toBeNull();
    expect($adjustRow->qty_out_base)->toBe('12.000000');
});

test('BR-06: the approver cannot be the same person who counted a line with a difference', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $labManager = labManagerUser();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '0']);
    app(LedgerService::class)->receive($container->id, '100.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $labManager->id));

    $service = app(StockTakeService::class);
    $stockTake = $service->create($lab, now()->toDateString(), $labManager);
    $line = $stockTake->lines()->where('container_id', $container->fresh()->id)->firstOrFail();
    $service->recordCount($line, '90.000000', $labManager, 'ทดสอบ');
    $service->submitForApproval($stockTake->fresh());

    expect(fn () => $service->approve($stockTake->fresh(), $labManager))
        ->toThrow(InvalidStockTakeException::class);
});

test('a line with no difference does not produce any ledger row on approval', function () {
    $lab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $counter = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id, 'status' => 'IN_USE', 'remaining_qty_base' => '0']);
    app(LedgerService::class)->receive($container->id, '100.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $counter->id));

    $service = app(StockTakeService::class);
    $stockTake = $service->create($lab, now()->toDateString(), $counter);
    $line = $stockTake->lines()->where('container_id', $container->fresh()->id)->firstOrFail();
    $service->recordCount($line, '100.000000', $counter);
    $service->submitForApproval($stockTake->fresh());

    $rowCountBefore = StockLedger::where('item_id', $item->id)->count();
    $labManager = labManagerUser();
    $service->approve($stockTake->fresh(), $labManager);

    expect(StockLedger::where('item_id', $item->id)->count())->toBe($rowCountBefore);
});
