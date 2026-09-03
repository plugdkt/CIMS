<?php

use App\Domain\Inventory\Services\LedgerHasher;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function appendLedgerRow(int $itemId, int $unitId, int $userId, string $qtyIn, string $balance): StockLedger
{
    $hasher = app(LedgerHasher::class);
    $last = StockLedger::where('item_id', $itemId)->orderByDesc('id')->first();

    $row = new StockLedger([
        'item_id' => $itemId,
        'txn_date' => now()->toDateString(),
        'txn_type' => $last === null ? 'OPENING' : 'RECEIVE',
        'qty_in_base' => $qtyIn,
        'qty_out_base' => '0.000000',
        'balance_base' => $balance,
        'display_unit_id' => $unitId,
        'created_by' => $userId,
        'created_at' => now(),
        'prev_row_hash' => $last?->row_hash,
    ]);
    $row->row_hash = $hasher->compute($row);
    $row->save();

    return $row;
}

test('ledger:verify reports success for an intact chain (CT-03-style check)', function () {
    $item = makeItem();
    $unit = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    appendLedgerRow($item->id, $unit->id, $user->id, '10.000000', '10.000000');
    appendLedgerRow($item->id, $unit->id, $user->id, '5.000000', '15.000000');

    $this->artisan('ledger:verify')
        ->assertExitCode(0)
        ->expectsOutputToContain('chain สมบูรณ์ 100%');
});

test('ledger:verify reports the broken row when a chain has been tampered with', function () {
    $item = makeItem();
    $unit = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    appendLedgerRow($item->id, $unit->id, $user->id, '10.000000', '10.000000');
    $good = appendLedgerRow($item->id, $unit->id, $user->id, '5.000000', '15.000000');

    // Simulate a corrupted history row — the append-only trigger blocks UPDATE,
    // so the only way to model this is inserting a bad row directly (e.g. as if
    // it came from a compromised migration or an out-of-band write).
    $tampered = new StockLedger([
        'item_id' => $item->id,
        'txn_date' => now()->toDateString(),
        'txn_type' => 'RECEIVE',
        'qty_in_base' => '2.000000',
        'qty_out_base' => '0.000000',
        'balance_base' => '17.000000',
        'display_unit_id' => $unit->id,
        'created_by' => $user->id,
        'created_at' => now(),
        'prev_row_hash' => $good->row_hash,
        'row_hash' => str_repeat('0', 64),
    ]);
    $tampered->save();

    $this->artisan('ledger:verify')
        ->assertExitCode(1)
        ->expectsOutputToContain("id={$tampered->id}");
});
