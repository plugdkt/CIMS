<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Reporting\Exports\DeadStockExport;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Writes a RECEIVE row with an arbitrary txn_date, bypassing LedgerService (which always uses now()). */
function ledgerRowDated(int $itemId, int $containerId, int $userId, string $txnDate): StockLedger
{
    $row = new StockLedger([
        'item_id' => $itemId,
        'container_id' => $containerId,
        'txn_date' => $txnDate,
        'txn_type' => 'RECEIVE',
        'qty_in_base' => '10.000000',
        'qty_out_base' => '0.000000',
        'balance_base' => '10.000000',
        'display_unit_id' => Unit::where('code', 'g')->value('id'),
        'created_by' => $userId,
        'created_at' => now(),
        'row_hash' => bin2hex(random_bytes(32)),
    ]);
    $row->save();

    return $row;
}

test('§7.8 dead stock lists containers with stock but no ledger movement in over 12 months', function () {
    $item = makeItem();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10.000000']);
    ledgerRowDated($item->id, $container->id, $user->id, now()->subMonths(15)->toDateString());

    $rows = (new DeadStockExport())->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][0])->toBe($item->name_th);
});

test('a container with a recent movement is not dead stock', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    expect((new DeadStockExport())->collection())->toHaveCount(0);
});

test('an empty container (remaining_qty_base = 0) is never dead stock', function () {
    $item = makeItem();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id, 'status' => 'EMPTY', 'remaining_qty_base' => '0.000000']);
    ledgerRowDated($item->id, $container->id, $user->id, now()->subMonths(15)->toDateString());

    expect((new DeadStockExport())->collection())->toHaveCount(0);
});

test('filtering by lab only includes containers physically in that lab', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);
    $itemA = makeItem();
    $itemB = makeItem();
    $user = User::factory()->create();

    $containerA = makeContainer(['item_id' => $itemA->id, 'location_id' => $locationA->id, 'status' => 'IN_USE', 'remaining_qty_base' => '5.000000']);
    ledgerRowDated($itemA->id, $containerA->id, $user->id, now()->subMonths(15)->toDateString());
    $containerB = makeContainer(['item_id' => $itemB->id, 'location_id' => $locationB->id, 'status' => 'IN_USE', 'remaining_qty_base' => '5.000000']);
    ledgerRowDated($itemB->id, $containerB->id, $user->id, now()->subMonths(15)->toDateString());

    $rows = (new DeadStockExport($labA->id))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][0])->toBe($itemA->name_th);
});
