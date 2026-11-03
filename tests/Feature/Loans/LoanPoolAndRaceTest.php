<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 1, after QA: a RETIRED pool frees its (branch, item) pair (pool_key is NULL unless the row
 * is an ACTIVE POOL), an ACTIVE pool still blocks, and a collision that slips past the service's own
 * pre-check becomes a Thai message instead of a 500.
 */

const POOL_NOTE = 'เหตุผลที่ยาวพอสำหรับทดสอบ';

test('after a POOL is retired the same branch and item can be registered again; an ACTIVE pool still blocks it', function () {
    [$lab, $manager, $approver] = loanManagers();
    $item = makeCountItem();
    $first = registerLoanAsset($manager, $lab, 'POOL', 5, $item);

    expect(fn () => registerLoanAsset($manager, $lab, 'POOL', 3, $item))->toThrow(LoanDomainException::class, __('loans.error.pool_exists'));

    loanService()->retire($manager, $first, POOL_NOTE, $approver->id);

    $second = registerLoanAsset($manager, $lab, 'POOL', 3, $item);
    expect($second->id)->not->toBe($first->id);
    expect($second->status)->toBe('ACTIVE')->and($first->fresh()->status)->toBe('RETIRED');

    expect(fn () => registerLoanAsset($manager, $lab, 'POOL', 1, $item))->toThrow(LoanDomainException::class, __('loans.error.pool_exists'));
});

test('the database unique key agrees: a second ACTIVE pool is refused, a pool next to a RETIRED one is accepted', function () {
    [$lab, $manager, $approver] = loanManagers();
    $item = makeCountItem();
    $first = registerLoanAsset($manager, $lab, 'POOL', 5, $item);
    $row = fn () => [
        'ulid' => (string) \Illuminate\Support\Str::ulid(), 'lab_id' => $lab->id, 'item_id' => $item->id, 'tracking_mode' => 'POOL',
        'qty_total' => 1, 'qty_on_loan' => 0, 'qty_unavailable' => 0, 'status' => 'ACTIVE',
    ];

    expect(fn () => DB::table('loan_assets')->insert($row()))->toThrow(\Illuminate\Database\QueryException::class);

    loanService()->retire($manager, $first, POOL_NOTE, $approver->id);
    DB::table('loan_assets')->insert($row());   // accepted: the retired row's pool_key is NULL
    expect(LoanAsset::where('lab_id', $lab->id)->where('item_id', $item->id)->count())->toBe(2);
    expect(fn () => DB::table('loan_assets')->insert($row()))->toThrow(\Illuminate\Database\QueryException::class);
});

test('a collision that slips past the pre-check (the clashing row appears just before the insert) is a Thai message, not a 500', function (string $clash) {
    [$lab, $manager] = loanManagers();
    $item = makeCountItem();
    $fired = false;

    LoanAsset::creating(function (LoanAsset $asset) use (&$fired, $lab, $item, $clash) {
        if ($fired) {
            return;
        }
        $fired = true;
        // Somebody else gets in first: after the service's own checks, before its INSERT.
        DB::table('loan_assets')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(), 'lab_id' => $lab->id, 'item_id' => $item->id,
            'tracking_mode' => $clash === 'pool' ? 'POOL' : 'UNIT', 'asset_tag' => $clash === 'tag' ? 'RACE-TAG-1' : null,
            'qty_total' => 1, 'qty_on_loan' => 0, 'qty_unavailable' => 0, 'status' => 'ACTIVE',
        ]);
    });

    $data = $clash === 'pool'
        ? ['lab_id' => $lab->id, 'item_id' => $item->id, 'tracking_mode' => 'POOL', 'qty' => 3]
        : ['lab_id' => $lab->id, 'item_id' => $item->id, 'tracking_mode' => 'UNIT', 'qty' => 1, 'asset_tag' => 'RACE-TAG-1'];

    expect(fn () => loanService()->register($manager, $data))
        ->toThrow(LoanDomainException::class, __($clash === 'pool' ? 'loans.error.pool_exists' : 'loans.error.tag_exists'));
    expect(LoanMovement::count())->toBe(0);
})->with(['pool', 'tag']);
