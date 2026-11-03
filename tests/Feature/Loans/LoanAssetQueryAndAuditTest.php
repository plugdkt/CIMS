<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 1, after QA: ?lab_id= is for ADMIN only (anyone else may send anything, it is never used
 * and never an error), and changing max_loan_days / replacement_value is audited old -> new.
 */

test('a non-ADMIN may send any ?lab_id= (even a nonsense one): no validation error, and it is never used', function () {
    [$labA, $managerA] = loanManagers();
    [$labB, $managerB] = loanManagers();
    registerLoanAsset($managerA, $labA, 'POOL', 3, makeCountItem(['name_th' => 'ZQUERYA']));
    registerLoanAsset($managerB, $labB, 'POOL', 3, makeCountItem(['name_th' => 'ZQUERYB']));
    $counter = scientistUser(['lab_id' => $labA->id]);

    foreach ([999999, $labB->id, 'abc', ''] as $labParam) {
        $this->actingAs($managerA)->get(route('loans.assets.index', ['lab_id' => $labParam]))->assertOk()->assertSee('ZQUERYA')->assertDontSee('ZQUERYB');
        $this->actingAs($managerA)->get(route('loans.available', ['lab_id' => $labParam]))->assertOk()->assertSee('ZQUERYA')->assertDontSee('ZQUERYB');
        $this->actingAs($counter)->get(route('loans.available', ['lab_id' => $labParam]))->assertOk()->assertSee('ZQUERYA')->assertDontSee('ZQUERYB');
    }
});

test('ADMIN: a ?lab_id= that does not exist is a validation error; a real one narrows the list', function () {
    [$labA, $managerA] = loanManagers();
    registerLoanAsset($managerA, $labA, 'POOL', 3, makeCountItem(['name_th' => 'ZADMINQ']));
    $admin = adminUser();

    $this->actingAs($admin)->get(route('loans.assets.index', ['lab_id' => 999999]))->assertSessionHasErrors('lab_id');
    $this->actingAs($admin)->get(route('loans.available', ['lab_id' => 999999]))->assertSessionHasErrors('lab_id');
    $this->actingAs($admin)->get(route('loans.assets.index', ['lab_id' => $labA->id]))->assertOk()->assertSee('ZADMINQ');
});

test('updating max_loan_days or replacement_value writes one LOAN_ASSET_UPDATE audit row, old to new, only the changed keys; no change, no row', function () {
    [$lab, $manager] = loanManagers();
    $asset = loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 4, 'max_loan_days' => 7, 'replacement_value' => '100.00']);

    // Only the days change.
    $this->actingAs($manager)->put(route('loans.assets.update', $asset), ['max_loan_days' => 14, 'replacement_value' => '100.00', 'condition_note' => 'สภาพดี'])->assertRedirect();
    $row = AuditLog::where('action', 'LOAN_ASSET_UPDATE')->where('entity_id', $asset->id)->firstOrFail();
    expect($row->old_value)->toBe(['max_loan_days' => 7]);
    expect($row->new_value)->toBe(['max_loan_days' => 14]);
    expect($row->user_id)->toBe($manager->id);

    // Only the value changes.
    $this->actingAs($manager)->put(route('loans.assets.update', $asset), ['max_loan_days' => 14, 'replacement_value' => '250.50'])->assertRedirect();
    $second = AuditLog::where('action', 'LOAN_ASSET_UPDATE')->where('entity_id', $asset->id)->orderByDesc('id')->firstOrFail();
    expect($second->old_value)->toBe(['replacement_value' => '100.00']);
    expect($second->new_value)->toBe(['replacement_value' => '250.50']);

    // Only the free-text note changes: not audited.
    $before = AuditLog::where('action', 'LOAN_ASSET_UPDATE')->count();
    $this->actingAs($manager)->put(route('loans.assets.update', $asset), ['max_loan_days' => 14, 'replacement_value' => '250.50', 'condition_note' => 'แก้เฉพาะหมายเหตุ'])->assertRedirect();
    expect(AuditLog::where('action', 'LOAN_ASSET_UPDATE')->count())->toBe($before);
});
