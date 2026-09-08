<?php

use App\Domain\Inventory\Services\LedgerHasher;
use App\Domain\Inventory\Services\LedgerSnapshotService;
use App\Models\LedgerSnapshot;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Writes one stock_ledger row with an arbitrary txn_date, bypassing LedgerService (always "now"). */
function snapshotLedgerRow(int $itemId, int $containerId, int $userId, string $txnDate, string $qtyIn, string $qtyOut, string $balance): StockLedger
{
    $hasher = app(LedgerHasher::class);
    $last = StockLedger::where('item_id', $itemId)->orderByDesc('id')->first();

    $row = new StockLedger([
        'item_id' => $itemId,
        'container_id' => $containerId,
        'txn_date' => $txnDate,
        'txn_type' => bccomp($qtyIn, '0', 6) > 0 ? 'RECEIVE' : 'ISSUE',
        'qty_in_base' => $qtyIn,
        'qty_out_base' => $qtyOut,
        'balance_base' => $balance,
        'display_unit_id' => Unit::where('code', 'g')->value('id'),
        'created_by' => $userId,
        'created_at' => now(),
        'prev_row_hash' => $last?->row_hash,
    ]);
    $row->row_hash = $hasher->compute($row);
    $row->save();

    return $row;
}

test('T-047: a fresh item\'s first snapshot has zero opening and closing matches the last row\'s balance', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    $month = Carbon::create(2026, 8, 15);

    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-05', '100.000000', '0.000000', '100.000000');
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-20', '0.000000', '30.000000', '70.000000');

    $snapshots = app(LedgerSnapshotService::class)->generateForPeriod($month);
    $snapshot = $snapshots->firstWhere('item_id', $item->id);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->period_ym)->toBe('2569-08');
    expect($snapshot->opening_base)->toBe('0.000000');
    expect($snapshot->total_in_base)->toBe('100.000000');
    expect($snapshot->total_out_base)->toBe('30.000000');
    expect($snapshot->closing_base)->toBe('70.000000');
});

test('T-047: a later month\'s opening_base chains from the previous month\'s closing_base', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();

    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-10', '100.000000', '0.000000', '100.000000');
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-09-10', '0.000000', '20.000000', '80.000000');

    $service = app(LedgerSnapshotService::class);
    $service->generateForPeriod(Carbon::create(2026, 8, 1));
    $septemberSnapshots = $service->generateForPeriod(Carbon::create(2026, 9, 1));

    $september = $septemberSnapshots->firstWhere('item_id', $item->id);
    expect($september->opening_base)->toBe('100.000000');
    expect($september->closing_base)->toBe('80.000000');
});

test('T-047: a month with no activity still gets a snapshot once the item has an earlier one, carrying the balance forward', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-10', '50.000000', '0.000000', '50.000000');

    $service = app(LedgerSnapshotService::class);
    $service->generateForPeriod(Carbon::create(2026, 8, 1));
    $septemberSnapshots = $service->generateForPeriod(Carbon::create(2026, 9, 1)); // no movement in September

    $september = $septemberSnapshots->firstWhere('item_id', $item->id);
    expect($september)->not->toBeNull();
    expect($september->opening_base)->toBe('50.000000');
    expect($september->total_in_base)->toBe('0.000000');
    expect($september->closing_base)->toBe('50.000000');
});

test('T-047: an item with no ledger activity and no prior snapshot is skipped entirely', function () {
    makeItem(); // no containers, no ledger rows at all

    $snapshots = app(LedgerSnapshotService::class)->generateForPeriod(Carbon::create(2026, 8, 1));

    expect($snapshots)->toHaveCount(0);
    expect(LedgerSnapshot::count())->toBe(0);
});

test('T-047: skipping straight to a later month still derives opening_base from ledger history before that month', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-06-10', '40.000000', '0.000000', '40.000000');
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-09-05', '0.000000', '15.000000', '25.000000');

    // No June/July/August snapshot ever generated — jump straight to September.
    $septemberSnapshots = app(LedgerSnapshotService::class)->generateForPeriod(Carbon::create(2026, 9, 1));

    $september = $septemberSnapshots->firstWhere('item_id', $item->id);
    expect($september->opening_base)->toBe('40.000000');
    expect($september->closing_base)->toBe('25.000000');
});

test('T-047: re-running the same period is idempotent (no duplicate row, values recomputed)', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-10', '10.000000', '0.000000', '10.000000');

    $service = app(LedgerSnapshotService::class);
    $service->generateForPeriod(Carbon::create(2026, 8, 1));
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-25', '5.000000', '0.000000', '15.000000');
    $service->generateForPeriod(Carbon::create(2026, 8, 1)); // re-run for the same period

    expect(LedgerSnapshot::where('item_id', $item->id)->where('period_ym', '2569-08')->count())->toBe(1);
    expect(LedgerSnapshot::where('item_id', $item->id)->first()->closing_base)->toBe('15.000000');
});

test('T-047: last_ledger_id points at the actual last stock_ledger row within the period', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-05', '10.000000', '0.000000', '10.000000');
    $lastRow = snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-20', '5.000000', '0.000000', '15.000000');

    $snapshots = app(LedgerSnapshotService::class)->generateForPeriod(Carbon::create(2026, 8, 1));

    expect($snapshots->firstWhere('item_id', $item->id)->last_ledger_id)->toBe($lastRow->id);
});
