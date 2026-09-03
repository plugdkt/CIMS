<?php

use App\Domain\Inventory\Services\LedgerHasher;
use App\Models\StockLedger;

function ledgerRow(array $overrides = []): StockLedger
{
    return new StockLedger(array_merge([
        'item_id' => 1,
        'txn_type' => 'RECEIVE',
        'qty_in_base' => '10.000000',
        'qty_out_base' => '0.000000',
        'balance_base' => '10.000000',
        'created_at' => now(),
        'created_by' => 5,
        'prev_row_hash' => null,
    ], $overrides));
}

// UT-05
test('LedgerHasher::compute on the same row twice yields the same hash', function () {
    $hasher = new LedgerHasher();
    $row = ledgerRow();

    expect($hasher->compute($row))->toBe($hasher->compute($row));
});

test('the hash is a 64-character sha256 hex digest', function () {
    $hasher = new LedgerHasher();

    expect($hasher->compute(ledgerRow()))->toBeString()->toHaveLength(64);
});

test('changing balance_base changes the hash', function () {
    $hasher = new LedgerHasher();

    $original = $hasher->compute(ledgerRow());
    $changed = $hasher->compute(ledgerRow(['balance_base' => '11.000000']));

    expect($changed)->not->toBe($original);
});

test('changing prev_row_hash changes the hash (chains break if tampered with)', function () {
    $hasher = new LedgerHasher();

    $original = $hasher->compute(ledgerRow());
    $changed = $hasher->compute(ledgerRow(['prev_row_hash' => str_repeat('a', 64)]));

    expect($changed)->not->toBe($original);
});
