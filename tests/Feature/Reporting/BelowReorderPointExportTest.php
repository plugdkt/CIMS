<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Reporting\Exports\BelowReorderPointExport;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('§7.8 lists items whose current balance is below their reorder point', function () {
    $item = makeItem(['reorder_point_base' => '20.000000']);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    $rows = (new BelowReorderPointExport())->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][1])->toBe($item->name_th);
});

test('an item above its reorder point is not listed', function () {
    $item = makeItem(['reorder_point_base' => '20.000000']);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    expect((new BelowReorderPointExport())->collection())->toHaveCount(0);
});

test('filtering by lab only includes items with a container physically in that lab', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);
    $itemA = makeItem(['reorder_point_base' => '20.000000']);
    $itemB = makeItem(['reorder_point_base' => '20.000000']);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    $containerA = makeContainer(['item_id' => $itemA->id, 'location_id' => $locationA->id]);
    app(LedgerService::class)->receive($containerA->id, '5.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));
    $containerB = makeContainer(['item_id' => $itemB->id, 'location_id' => $locationB->id]);
    app(LedgerService::class)->receive($containerB->id, '5.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    $rows = (new BelowReorderPointExport($labA->id))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][1])->toBe($itemA->name_th);
});
