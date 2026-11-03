<?php

use App\Models\LoanMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051: the loan.* permission codes and which role holds which, pinned exactly (PermissionSeeder's
 * seedLoanPermissions). LAB_MANAGER and AUDITOR are identical; ADMIN holds everything except the advisor
 * step (BR-L03); loan.verify is ADMIN only; and ADMIN's right to write loan_movements is an exception for
 * this module only: stock_ledger stays closed to ADMIN.
 */

if (! function_exists('loanCodesOf')) {
    /** @return list<string> */
    function loanCodesOf(string $roleCode): array
    {
        return Role::where('code', $roleCode)->firstOrFail()->permissions
            ->pluck('code')->filter(fn ($c) => str_starts_with($c, 'loan.'))->sort()->values()->all();
    }
}

const LOAN_CODES = [
    'loan.approve', 'loan.approve_advisor', 'loan.asset_manage', 'loan.cancel', 'loan.create', 'loan.handout',
    'loan.incident_resolve', 'loan.renew', 'loan.report', 'loan.verify', 'loan.view_all', 'loan.view_own', 'loan.view_queue',
];

test('all thirteen loan.* codes exist', function () {
    expect(Permission::where('code', 'like', 'loan.%')->pluck('code')->sort()->values()->all())->toBe(LOAN_CODES);
});

test('LAB_MANAGER and AUDITOR hold exactly the same loan.* codes, and exactly these', function () {
    $expected = [
        'loan.approve', 'loan.asset_manage', 'loan.cancel', 'loan.create', 'loan.handout', 'loan.incident_resolve',
        'loan.renew', 'loan.report', 'loan.view_all', 'loan.view_own', 'loan.view_queue',
    ];

    expect(loanCodesOf('LAB_MANAGER'))->toBe($expected);
    expect(loanCodesOf('AUDITOR'))->toBe(loanCodesOf('LAB_MANAGER'));
});

test('ADMIN holds every loan.* code except loan.approve_advisor', function () {
    $expected = array_values(array_diff(LOAN_CODES, ['loan.approve_advisor']));

    expect(loanCodesOf('ADMIN'))->toBe($expected);
    expect(loanCodesOf('ADMIN'))->not->toContain('loan.approve_advisor');
});

test('requesters borrow for themselves; ADVISOR also approves their students; SCIENTIST is the counter role', function () {
    $borrower = ['loan.cancel', 'loan.create', 'loan.renew', 'loan.view_own'];

    expect(loanCodesOf('STUDENT'))->toBe($borrower);
    expect(loanCodesOf('STAFF'))->toBe($borrower);
    expect(loanCodesOf('ADVISOR'))->toBe(['loan.approve_advisor', 'loan.cancel', 'loan.create', 'loan.renew', 'loan.view_own']);
    expect(loanCodesOf('SCIENTIST'))->toBe(['loan.cancel', 'loan.create', 'loan.handout', 'loan.renew', 'loan.view_own', 'loan.view_queue']);
});

test('only ADMIN holds loan.verify; only the advisor role holds loan.approve_advisor; managing the register is the managers and ADMIN', function () {
    $holders = fn (string $code) => Role::whereHas('permissions', fn ($q) => $q->where('code', $code))->pluck('code')->sort()->values()->all();

    expect($holders('loan.verify'))->toBe(['ADMIN']);
    expect($holders('loan.approve_advisor'))->toBe(['ADVISOR']);
    expect($holders('loan.asset_manage'))->toBe(['ADMIN', 'AUDITOR', 'LAB_MANAGER']);
    expect($holders('loan.approve'))->toBe(['ADMIN', 'AUDITOR', 'LAB_MANAGER']);
});

test('seeding twice changes nothing (the loan grants are idempotent)', function () {
    $before = [loanCodesOf('LAB_MANAGER'), loanCodesOf('ADMIN'), loanCodesOf('SCIENTIST')];
    $count = Permission::count();

    $this->seed(\Database\Seeders\PermissionSeeder::class);

    expect([loanCodesOf('LAB_MANAGER'), loanCodesOf('ADMIN'), loanCodesOf('SCIENTIST')])->toBe($before);
    expect(Permission::count())->toBe($count);
});

test('the older permission sets are untouched by the loan block (a manager still has no ledger.verify, ADMIN still no ledger.adjust)', function () {
    $codes = fn (string $role) => Role::where('code', $role)->firstOrFail()->permissions->pluck('code')->all();

    expect($codes('AUDITOR'))->not->toContain('ledger.verify')->not->toContain('requisition.issue');
    expect($codes('ADMIN'))->not->toContain('ledger.adjust')->not->toContain('disposal.approve')->not->toContain('requisition.issue');
});

test('ADMIN may write loan_movements but still cannot write stock_ledger by any route (CEO exception is for loans only)', function () {
    $admin = adminUser();
    $lab = makeLab();
    $ledgerBefore = StockLedger::count();

    // The loan ledger: allowed.
    $asset = loanService()->register($admin, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 5]);
    expect(LoanMovement::where('loan_asset_id', $asset->id)->count())->toBe(1);

    // The stock ledger: still closed to ADMIN.
    $this->actingAs($admin)->get(route('adjustments.create'))->assertForbidden();
    $this->actingAs($admin)->post(route('adjustments.store'), [])->assertForbidden();

    expect(StockLedger::count())->toBe($ledgerBefore);
});
