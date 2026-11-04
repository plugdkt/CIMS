<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('isBranchManager is true for LAB_MANAGER and AUDITOR, false for everyone else', function () {
    expect(labManagerUser()->isBranchManager())->toBeTrue();
    expect(auditorUser()->isBranchManager())->toBeTrue();
    expect(scientistUser()->isBranchManager())->toBeFalse();
    expect(adminUser()->isBranchManager())->toBeFalse();
    expect(studentUser()->isBranchManager())->toBeFalse();

    // A Super Admin who holds both ADMIN and LAB_MANAGER/AUDITOR is never branch-restricted
    $lab = makeLab();
    $superAdmin = adminUser(['lab_id' => $lab->id]);
    $superAdmin->roles()->attach(Role::where('code', 'LAB_MANAGER')->firstOrFail());
    $superAdmin->roles()->attach(Role::where('code', 'AUDITOR')->firstOrFail());
    expect($superAdmin->isBranchManager())->toBeFalse();
});

test('AUDITOR (ผู้ดูแลคลัง) now holds the exact same operational grants as LAB_MANAGER', function () {
    $labManagerCodes = Role::where('code', 'LAB_MANAGER')->firstOrFail()->permissions->pluck('code')->sort()->values()->all();
    $auditorCodes = Role::where('code', 'AUDITOR')->firstOrFail()->permissions->pluck('code')->sort()->values()->all();

    expect($auditorCodes)->toBe($labManagerCodes);
});

test('AUDITOR lost its old read-only grants, but ADMIN still holds them', function () {
    $auditorCodes = Role::where('code', 'AUDITOR')->firstOrFail()->permissions->pluck('code')->all();
    $adminCodes = Role::where('code', 'ADMIN')->firstOrFail()->permissions->pluck('code')->all();

    expect($auditorCodes)->not->toContain('ledger.verify')->not->toContain('audit.view');
    expect($adminCodes)->toContain('ledger.verify')->toContain('audit.view');
});

test('an AUDITOR only sees requisitions filed in their own branch, same as a LAB_MANAGER', function () {
    $labA = makeLab();
    $labB = makeLab();
    $requisitionInA = makeRequisition(studentUser(), ['lab_id' => $labA->id]);

    $auditorInA = auditorUser(['lab_id' => $labA->id]);
    $this->actingAs($auditorInA)->get(route('requisitions.show', $requisitionInA))->assertOk();

    $auditorInB = auditorUser(['lab_id' => $labB->id]);
    $this->actingAs($auditorInB)->get(route('requisitions.show', $requisitionInA))->assertStatus(403);
});

test('an AUDITOR of a different branch gets 403 approving a disposal in another branch, just like a LAB_MANAGER', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $location = makeLocationForLab($lab);
    $item = makeItem();
    $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
    // disposal.request moved off SCIENTIST on 2026-09-22 — the requester is a manager now.
    $requester = auditorUser(['lab_id' => $lab->id]);
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '50.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id),
    );

    $this->actingAs($requester)->post(route('disposals.store'), [
        'barcode' => $container->fresh()->barcode,
        'qty' => '20.000000',
        'reason' => 'EXPIRED',
        'disposal_date' => now()->toDateString(),
    ]);
    $disposal = \App\Models\Disposal::firstOrFail();

    $otherAuditor = auditorUser(['lab_id' => $otherLab->id]);
    $this->actingAs($otherAuditor)->post(route('disposals.approve', $disposal))->assertStatus(403);

    $ownAuditor = auditorUser(['lab_id' => $lab->id]);
    $this->actingAs($ownAuditor)->post(route('disposals.approve', $disposal))
        ->assertRedirect(route('disposals.show', $disposal));
});

test('an AUDITOR is scoped to their own branch in stock-in.index and cannot see other branches stock', function () {
    $labA = makeLab();
    $labB = makeLab();
    $auditorA = auditorUser(['lab_id' => $labA->id]);

    $locA = makeLocationForLab($labA);
    $locB = makeLocationForLab($labB);

    $contA = makeContainer(['location_id' => $locA->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $contB = makeContainer(['location_id' => $locB->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    $this->actingAs($auditorA)->get(route('stock-in.index'))
        ->assertOk()
        ->assertSee($contA->barcode)
        ->assertDontSee($contB->barcode);
});

test('an AUDITOR is scoped to their own branch in items.show and cannot see other branches containers', function () {
    $labA = makeLab();
    $labB = makeLab();
    $auditorA = auditorUser(['lab_id' => $labA->id]);

    $item = makeItem();
    $locA = makeLocationForLab($labA);
    $locB = makeLocationForLab($labB);

    $contA = makeContainer(['item_id' => $item->id, 'location_id' => $locA->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);
    $contB = makeContainer(['item_id' => $item->id, 'location_id' => $locB->id, 'status' => 'SEALED', 'remaining_qty_base' => '10.000000']);

    $this->actingAs($auditorA)->get(route('items.show', $item))
        ->assertOk()
        ->assertSee($contA->barcode)
        ->assertDontSee($contB->barcode);
});

test('approvals.md K: who holds requisition.approve_scientist and requisition.issue is pinned: scientists and both warehouse manager roles, never ADMIN, never a requester role', function () {
    $holders = fn (string $code) => Role::whereHas('permissions', fn ($q) => $q->where('code', $code))->pluck('code')->sort()->values()->all();

    expect($holders('requisition.approve_scientist'))->toBe(['AUDITOR', 'LAB_MANAGER', 'SCIENTIST']);
    expect($holders('requisition.issue'))->toBe(['AUDITOR', 'LAB_MANAGER', 'SCIENTIST']);
    // The 10% override stays with the managers only.
    expect($holders('requisition.issue_override'))->toBe(['AUDITOR', 'LAB_MANAGER']);
    // view_all is still not a scientist's: they see the submitted requisitions of their branch through the approver rule, not through view_all.
    expect($holders('requisition.view_all'))->toBe(['ADMIN', 'AUDITOR', 'LAB_MANAGER']);

    foreach (['ADMIN', 'STUDENT', 'STAFF', 'ADVISOR'] as $roleCode) {
        $codes = Role::where('code', $roleCode)->firstOrFail()->permissions->pluck('code')->all();
        expect($codes)->not->toContain('requisition.approve_scientist')->not->toContain('requisition.issue');
    }
});

test('approvals.md K: seeding again does not take approve_scientist / issue away from the roles that now hold them (the old detach blocks are gone)', function () {
    $this->seed(\Database\Seeders\PermissionSeeder::class);
    $this->seed(\Database\Seeders\PermissionSeeder::class);

    $codes = fn (string $role) => Role::where('code', $role)->firstOrFail()->permissions->pluck('code')->all();
    expect($codes('SCIENTIST'))->toContain('requisition.approve_scientist')->toContain('requisition.issue');
    expect($codes('LAB_MANAGER'))->toContain('requisition.approve_scientist')->toContain('requisition.issue');
    expect($codes('AUDITOR'))->toContain('requisition.approve_scientist')->toContain('requisition.issue');
    // What SCIENTIST lost on 2026-09-21/22 stays lost.
    expect($codes('SCIENTIST'))->not->toContain('requisition.view_all')->not->toContain('stocktake.manage')->not->toContain('disposal.request')->not->toContain('report.view');
    // And AUDITOR's old read-only grants stay detached.
    expect($codes('AUDITOR'))->not->toContain('ledger.verify')->not->toContain('audit.view');
});

test('approvals.md K: a SCIENTIST is branch-scoped by lab_id like everyone else even though isBranchManager() is false for them', function () {
    $lab = makeLab();
    $requisition = makeRequisition(staffUser(['lab_id' => $lab->id]), ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    expect(scientistUser(['lab_id' => $lab->id])->isBranchManager())->toBeFalse();
    expect(scientistUser(['lab_id' => $lab->id])->can('scientistDecide', $requisition))->toBeTrue();
    expect(scientistUser(['lab_id' => makeLab()->id])->can('scientistDecide', $requisition))->toBeFalse();
    expect(scientistUser(['lab_id' => null])->can('scientistDecide', $requisition))->toBeFalse();
});

test('ITM-2610-051: LAB_MANAGER and AUDITOR hold the identical loan.* permission set, so a manager flavour never changes lending rights', function () {
    $loanCodes = fn (string $role) => Role::where('code', $role)->firstOrFail()->permissions
        ->pluck('code')->filter(fn ($c) => str_starts_with($c, 'loan.'))->sort()->values()->all();

    expect($loanCodes('LAB_MANAGER'))->not->toBe([]);
    expect($loanCodes('AUDITOR'))->toBe($loanCodes('LAB_MANAGER'));
});
