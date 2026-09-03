<?php

use App\Domain\Inventory\Services\LedgerHasher;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * ST-08 / SEC-DB-02: stock_ledger and audit_logs must reject UPDATE/DELETE even via a raw
 * SQL statement on the app's own DB connection — not just via Eloquent model guards
 * (AuditLog::update()/delete() throw LogicException) or the trg_ledger_no_update /
 * trg_ledger_no_delete triggers (AGENT RULE #6, layer #2). This proves layer #1: the
 * cmis_app DB user itself has no UPDATE/DELETE grant on these two tables (see
 * docker/mariadb/init/02_restrict_app_grants.sql). A grant-level rejection surfaces as
 * MySQL error 1142 / SQLSTATE 42000 ("... command denied ..."), which is distinct from the
 * trigger's SQLSTATE 45000 "... is append-only" — asserting on 42000 here proves this is
 * actually exercising the grant, not incidentally re-triggering the trigger.
 */
function assertCommandDenied(callable $query): void
{
    try {
        $query();
        expect(false)->toBeTrue('expected the query to be rejected by the DB grant, but it succeeded');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('42000');
        expect($e->getMessage())->toContain('command denied');
    }
}

function makeLedgerRow(): StockLedger
{
    $item = makeItem();
    $unit = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();

    $row = new StockLedger([
        'item_id' => $item->id,
        'txn_date' => now()->toDateString(),
        'txn_type' => 'OPENING',
        'qty_in_base' => '10.000000',
        'qty_out_base' => '0.000000',
        'balance_base' => '10.000000',
        'display_unit_id' => $unit->id,
        'created_by' => $user->id,
        'created_at' => now(),
        'prev_row_hash' => null,
    ]);
    $row->row_hash = app(LedgerHasher::class)->compute($row);
    $row->save();

    return $row;
}

test('a raw UPDATE against stock_ledger is rejected at the DB grant level (ST-08)', function () {
    $row = makeLedgerRow();

    assertCommandDenied(fn () => DB::table('stock_ledger')
        ->where('id', $row->id)
        ->update(['qty_in_base' => '999.000000']));
});

test('a raw DELETE against stock_ledger is rejected at the DB grant level (ST-08)', function () {
    $row = makeLedgerRow();

    assertCommandDenied(fn () => DB::table('stock_ledger')->where('id', $row->id)->delete());
});

test('a raw UPDATE against audit_logs is rejected at the DB grant level (ST-08)', function () {
    $log = AuditLog::record(action: 'LOGIN_SUCCESS');

    assertCommandDenied(fn () => DB::table('audit_logs')
        ->where('id', $log->id)
        ->update(['result' => 'FAILURE']));
});

test('a raw DELETE against audit_logs is rejected at the DB grant level (ST-08)', function () {
    $log = AuditLog::record(action: 'LOGIN_SUCCESS');

    assertCommandDenied(fn () => DB::table('audit_logs')->where('id', $log->id)->delete());
});

test('the DB grant restriction is specific to stock_ledger/audit_logs, not the whole connection', function () {
    $item = makeItem();

    DB::table('items')->where('id', $item->id)->update(['name_th' => 'อัปเดตแล้ว']);

    expect(Item::find($item->id)->name_th)->toBe('อัปเดตแล้ว');
});
