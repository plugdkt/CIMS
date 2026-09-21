<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('currentBalance returns 0.000000 for an item with no ledger activity yet', function () {
    $item = makeItem();

    expect(app(StockBalanceService::class)->currentBalance($item))->toBe('0.000000');
});

test('currentBalance returns the latest stock_ledger balance for the item', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    app(LedgerService::class)->receive($container->id, '150.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    expect(app(StockBalanceService::class)->currentBalance($item))->toBe('150.000000');
});

test('isBelowReorderPoint is false for an item with no reorder point set', function () {
    $item = makeItem(['reorder_point_base' => '0.000000']);

    expect(app(StockBalanceService::class)->isBelowReorderPoint($item, '0.000000'))->toBeFalse();
});

test('isBelowReorderPoint is true only once the balance drops under the reorder point', function () {
    $item = makeItem(['reorder_point_base' => '100.000000']);
    $service = app(StockBalanceService::class);

    expect($service->isBelowReorderPoint($item, '150.000000'))->toBeFalse();
    expect($service->isBelowReorderPoint($item, '100.000000'))->toBeFalse();
    expect($service->isBelowReorderPoint($item, '99.999999'))->toBeTrue();
});
