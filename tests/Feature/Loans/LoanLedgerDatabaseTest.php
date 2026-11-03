<?php

use App\Models\LoanMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051: the database itself enforces what the service promises.
 *   - loan_movements is append-only: trg_loan_movements_no_update / _no_delete (SQLSTATE 45000) and, on the
 *     real grants, cmis_app has SELECT + INSERT only (see DatabaseGrantRestrictionTest);
 *   - chk_lm_approval: ADJUST_IN / ADJUST_OUT / REPLACE_IN need an approver who is not the creator;
 *   - chk_lm_qty / chk_la_qty: positive movement quantity, no negative counters, on-loan + unusable <= total.
 * Raw DB::table() inserts are used on purpose: they bypass the service, which is the point.
 */

function loanMovementRow(int $assetId, int $labId, int $createdBy, array $overrides = []): array
{
    return array_merge([
        'loan_asset_id' => $assetId, 'lab_id' => $labId, 'movement_type' => 'HANDOUT', 'qty' => '1.000000',
        'qty_total_after' => '10.000000', 'qty_on_loan_after' => '1.000000', 'qty_unavailable_after' => '0.000000',
        'created_by' => $createdBy, 'approved_by' => null, 'created_at' => now()->format('Y-m-d H:i:s.u'),
        'prev_row_hash' => null, 'row_hash' => str_repeat('a', 64),
    ], $overrides);
}

test('a raw UPDATE of loan_movements is rejected by the trigger (SQLSTATE 45000)', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $row = LoanMovement::where('loan_asset_id', $asset->id)->firstOrFail();

    try {
        DB::table('loan_movements')->where('id', $row->id)->update(['qty' => '99.000000']);
        expect(false)->toBeTrue('the UPDATE must be rejected');
    } catch (QueryException $e) {
        // Two independent layers refuse this: the DB grants (42000 "command denied", once
        // docker/mariadb/restrict_app_grants.sql has been applied) and the append-only trigger
        // (45000). Whichever fires first is enough; the row must be untouched either way.
        expect($e->getCode())->toBeIn(['42000', '45000']);
        expect($e->getMessage())->toMatch('/append-only|command denied/');
    }

    expect(LoanMovement::find($row->id)->qty)->toBe('10.000000');
});

test('a raw DELETE of loan_movements is rejected by the trigger (SQLSTATE 45000)', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $row = LoanMovement::where('loan_asset_id', $asset->id)->firstOrFail();

    try {
        DB::table('loan_movements')->where('id', $row->id)->delete();
        expect(false)->toBeTrue('the DELETE must be rejected');
    } catch (QueryException $e) {
        // Two independent layers refuse this: the DB grants (42000 "command denied", once
        // docker/mariadb/restrict_app_grants.sql has been applied) and the append-only trigger
        // (45000). Whichever fires first is enough; the row must be untouched either way.
        expect($e->getCode())->toBeIn(['42000', '45000']);
        expect($e->getMessage())->toMatch('/append-only|command denied/');
    }

    expect(LoanMovement::where('id', $row->id)->exists())->toBeTrue();
});

test('the Eloquent model also refuses update and delete of a movement', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $row = LoanMovement::where('loan_asset_id', $asset->id)->firstOrFail();

    expect(fn () => $row->update(['note' => 'แก้ภายหลัง']))->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);
});

test('chk_lm_approval: ADJUST_IN / ADJUST_OUT / REPLACE_IN / RETIRE without an approver, or approved by the creator, are refused', function (string $type) {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    foreach ([null, $manager->id] as $badApprover) {
        expect(fn () => DB::table('loan_movements')->insert(loanMovementRow($asset->id, $lab->id, $manager->id, ['movement_type' => $type, 'approved_by' => $badApprover])))
            ->toThrow(QueryException::class, 'chk_lm_approval');
    }

    // With a different approver the same row is accepted.
    DB::table('loan_movements')->insert(loanMovementRow($asset->id, $lab->id, $manager->id, ['movement_type' => $type, 'approved_by' => $approver->id]));
    expect(LoanMovement::where('movement_type', $type)->count())->toBe(1);
})->with(['ADJUST_IN', 'ADJUST_OUT', 'REPLACE_IN', 'RETIRE']);

test('chk_lm_approval does not apply to other movement types (no approver needed for a hand-out)', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    DB::table('loan_movements')->insert(loanMovementRow($asset->id, $lab->id, $manager->id));

    expect(LoanMovement::where('movement_type', 'HANDOUT')->count())->toBe(1);
});

test('chk_lm_qty: a movement needs a positive quantity, non-negative counters, and on-loan + unusable within the total', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    $bad = [
        'zero quantity' => ['qty' => '0.000000'],
        'negative quantity' => ['qty' => '-1.000000'],
        'negative total after' => ['qty_total_after' => '-1.000000'],
        'negative on-loan after' => ['qty_on_loan_after' => '-1.000000'],
        'negative unavailable after' => ['qty_unavailable_after' => '-1.000000'],
        'on-loan + unavailable above the total' => ['qty_on_loan_after' => '8.000000', 'qty_unavailable_after' => '3.000000'],
    ];
    foreach ($bad as $label => $override) {
        expect(fn () => DB::table('loan_movements')->insert(loanMovementRow($asset->id, $lab->id, $manager->id, $override)))
            ->toThrow(QueryException::class, 'chk_lm_qty');
    }
    expect(LoanMovement::where('loan_asset_id', $asset->id)->count())->toBe(1);   // only the REGISTER row
});

test('chk_la_qty: loan_assets refuses negative counters and on-loan + unavailable above the total', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    foreach ([['qty_total' => -1], ['qty_on_loan' => -1], ['qty_unavailable' => -1], ['qty_on_loan' => 8, 'qty_unavailable' => 3], ['qty_on_loan' => 11]] as $update) {
        expect(fn () => DB::table('loan_assets')->where('id', $asset->id)->update($update))
            ->toThrow(QueryException::class, 'chk_la_qty');
    }

    // The boundary itself is allowed.
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 6, 'qty_unavailable' => 4]);
    expect($asset->fresh()->qty_on_loan)->toBe('6.000000');
});

test('the migrations create both tables empty (nothing is backfilled)', function () {
    expect(DB::table('loan_assets')->count())->toBe(0);
    expect(DB::table('loan_movements')->count())->toBe(0);
});
