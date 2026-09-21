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
    $scientist = scientistUser();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $location->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '50.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $scientist->id),
    );

    $this->actingAs($scientist)->post(route('disposals.store'), [
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
