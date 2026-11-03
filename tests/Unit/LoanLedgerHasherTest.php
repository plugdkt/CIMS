<?php

use App\Domain\Inventory\Services\LedgerHasher;
use App\Domain\Loan\Services\LoanLedgerHasher;
use App\Models\LoanMovement;
use App\Models\StockLedger;
use Illuminate\Support\Carbon;

/*
 * ITM-2610-051 §6: the lending ledger has its OWN hash formula
 *   SHA256(prev | loan_asset_id | movement_type | qty | qty_total_after | qty_on_loan_after
 *          | qty_unavailable_after | created_at(Y-m-d\TH:i:s.u) | created_by)
 * The literal digests below were computed independently with `sha256sum` over the literal payload strings,
 * so a change in the formula (or its field order) fails here even if compute() and verify stay consistent.
 */

function loanRow(array $overrides = []): LoanMovement
{
    return new LoanMovement(array_merge([
        'loan_asset_id' => 7,
        'movement_type' => 'REGISTER',
        'qty' => '5.000000',
        'qty_total_after' => '5.000000',
        'qty_on_loan_after' => '0.000000',
        'qty_unavailable_after' => '0.000000',
        'created_at' => Carbon::parse('2026-11-04 09:30:15.123456'),
        'created_by' => 42,
        'prev_row_hash' => null,
    ], $overrides));
}

test('LoanLedgerHasher matches the independently computed digest for a first row (no previous hash)', function () {
    expect((new LoanLedgerHasher())->compute(loanRow()))
        ->toBe('6fa2607575a81f67809a6fe3f0fb930f39038c810e8f3cbd6d335dd0dafb2168');
});

test('LoanLedgerHasher matches the independently computed digest for a chained row (previous hash, microseconds)', function () {
    $row = loanRow([
        'movement_type' => 'HANDOUT', 'qty' => '2.000000', 'qty_on_loan_after' => '2.000000', 'created_by' => 43,
        'created_at' => Carbon::parse('2026-11-04 10:00:00.000001'), 'prev_row_hash' => 'ABC',
    ]);

    expect((new LoanLedgerHasher())->compute($row))
        ->toBe('fcfb34deca5faa8423dbb3d4fcdd43c75476f42e61db62f2ae87348d2269c9c8');
});

test('every field in the formula changes the hash; approved_by, note and external_ref do not', function () {
    $hasher = new LoanLedgerHasher();
    $base = $hasher->compute(loanRow());

    foreach ([
        ['loan_asset_id' => 8], ['movement_type' => 'RETIRE'], ['qty' => '6.000000'], ['qty_total_after' => '6.000000'],
        ['qty_on_loan_after' => '1.000000'], ['qty_unavailable_after' => '1.000000'], ['created_by' => 43],
        ['prev_row_hash' => 'x'], ['created_at' => Carbon::parse('2026-11-04 09:30:15.123457')],
    ] as $change) {
        expect($hasher->compute(loanRow($change)))->not->toBe($base, json_encode($change));
    }

    foreach ([['approved_by' => 9], ['note' => 'อะไรก็ได้ ยาวพอ'], ['external_ref' => 'REF-12345']] as $same) {
        expect($hasher->compute(loanRow($same)))->toBe($base, json_encode($same));
    }
});

test('the hash is a 64-character sha256 hex digest and is stable', function () {
    $hasher = new LoanLedgerHasher();

    expect($hasher->compute(loanRow()))->toHaveLength(64)->toBe($hasher->compute(loanRow()));
});

test('regression: the stock ledger hasher (LedgerHasher) is unchanged by the lending module', function () {
    $row = new StockLedger([
        'item_id' => 1, 'txn_type' => 'RECEIVE', 'qty_in_base' => '10.000000', 'qty_out_base' => '0.000000',
        'balance_base' => '10.000000', 'created_at' => Carbon::parse('2026-11-04 09:30:15.123456'),
        'created_by' => 5, 'prev_row_hash' => null,
    ]);

    expect((new LedgerHasher())->compute($row))
        ->toBe('3a325dd043ce61191b9ff4e33c344a814f291f748dceb78a454a320dc3578a92');
});
