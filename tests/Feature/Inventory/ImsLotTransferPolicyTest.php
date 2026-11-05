<?php

use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * ITM-2610-062: moving a lot from IMS into working stock needs `ims.manage` AND being the keeper of that kind of stock
 * (user_item_types, deny by default). The rule sits in ImsLotPolicy::transfer, so the single-lot route
 * (ims.lots.transfer / ims.lots.transfer.form) is closed by it. Since 2026-11-05 this is the ONLY way to move stock
 * (the paper-requisition page was removed). Science (loan) lots are never moved into working stock.
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->chem = makeItem(['name_th' => 'สารเคมีนโยบาย']);
    $this->supply = consumableItem(['name_th' => 'วัสดุนโยบาย']);
    $this->chemLot = makeImsLot($this->lab->id, $this->chem);
    $this->supplyLot = makeImsLot($this->lab->id, $this->supply);

    $this->payload = fn (array $o = []) => array_merge([
        'ims_doc_no' => 'WD-69-0100',
        'tracking_type' => 'BULK',
        'qty' => '300',
        'unit_id' => Unit::where('code', 'g')->firstOrFail()->id,
        'location_id' => $this->location->id,
    ], $o);
});

test('ImsLotPolicy::transfer: only a manager of the own branch who keeps THAT kind, while stock remains', function () {
    $chemKeeper = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);
    $supplyKeeper = auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => ['consumable']]);
    $both = labManagerUser(['lab_id' => $this->lab->id]);
    $none = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => []]);
    $otherBranch = labManagerUser(['lab_id' => $this->otherLab->id]);
    $noBranch = labManagerUser(['lab_id' => null]);
    $admin = adminUser(['lab_id' => $this->lab->id]);
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $can = fn ($user, ImsLot $lot) => Gate::forUser($user)->allows('transfer', $lot);

    expect($can($chemKeeper, $this->chemLot))->toBeTrue()
        ->and($can($chemKeeper, $this->supplyLot))->toBeFalse()
        ->and($can($supplyKeeper, $this->supplyLot))->toBeTrue()
        ->and($can($supplyKeeper, $this->chemLot))->toBeFalse()
        ->and($can($both, $this->chemLot))->toBeTrue()
        ->and($can($both, $this->supplyLot))->toBeTrue()
        ->and($can($none, $this->chemLot))->toBeFalse()
        ->and($can($none, $this->supplyLot))->toBeFalse()
        ->and($can($otherBranch, $this->chemLot))->toBeFalse()
        ->and($can($noBranch, $this->chemLot))->toBeFalse()
        // ADMIN keeps every kind but has no ims.manage (spec §3: no ledger writes).
        ->and($can($admin, $this->chemLot))->toBeFalse()
        ->and($can($scientist, $this->chemLot))->toBeFalse();

    // An emptied lot cannot be moved by anybody.
    $this->chemLot->update(['qty_remaining_base' => '0.000000']);
    expect($can($chemKeeper, $this->chemLot->fresh()))->toBeFalse();
});

test('POST straight to the ims.lots.transfer route: a manager who does not keep that kind gets 403 and nothing is written', function () {
    $chemKeeper = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);

    // A consumable lot, posted by the chemical keeper.
    $this->actingAs($chemKeeper)->post(route('ims.lots.transfer', $this->supplyLot), ($this->payload)())->assertForbidden();
    $this->actingAs($chemKeeper)->get(route('ims.lots.transfer.form', $this->supplyLot))->assertForbidden();

    // A manager with no kinds at all is denied for both.
    $none = auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => []]);
    $this->actingAs($none)->post(route('ims.lots.transfer', $this->chemLot), ($this->payload)())->assertForbidden();

    expect((float) $this->supplyLot->fresh()->qty_remaining_base)->toEqual(2000.0)
        ->and((float) $this->chemLot->fresh()->qty_remaining_base)->toEqual(2000.0)
        ->and(Container::count())->toBe(0)
        ->and(StockLedger::count())->toBe(0)
        ->and(ImsMovement::where('type', 'ISSUE')->count())->toBe(0);
});

test('the keeper of the kind can still move the lot through the lot route', function () {
    $chemKeeper = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);

    $this->actingAs($chemKeeper)->get(route('ims.lots.transfer.form', $this->chemLot))->assertOk();
    $this->actingAs($chemKeeper)->post(route('ims.lots.transfer', $this->chemLot), ($this->payload)())
        ->assertRedirect(route('ims.lots.index'));

    expect((float) $this->chemLot->fresh()->qty_remaining_base)->toEqual(1700.0)
        ->and(Container::where('item_id', $this->chem->id)->count())->toBe(1)
        ->and(ImsMovement::where('ims_lot_id', $this->chemLot->id)->where('type', 'ISSUE')->count())->toBe(1);
});

test('ADMIN (no ims.manage) is refused on the transfer route even though ADMIN keeps every kind', function () {
    $admin = adminUser(['lab_id' => $this->lab->id]);
    grantItemTypes($admin);   // rows do not matter: ims.manage is missing

    $this->actingAs($admin)->post(route('ims.lots.transfer', $this->chemLot), ($this->payload)())->assertForbidden();
    $this->actingAs($admin)->get(route('ims.lots.transfer.form', $this->chemLot))->assertForbidden();

    expect((float) $this->chemLot->fresh()->qty_remaining_base)->toEqual(2000.0)->and(Container::count())->toBe(0);
});

test('a keeper of the other branch gets 403 on the lot route even for the right kind', function () {
    $outsider = labManagerUser(['lab_id' => $this->otherLab->id]);

    $this->actingAs($outsider)->post(route('ims.lots.transfer', $this->chemLot), ($this->payload)())->assertForbidden();

    expect((float) $this->chemLot->fresh()->qty_remaining_base)->toEqual(2000.0);
});

test('ImsLotPolicy::transfer refuses a science (loan) lot for every user, even a keeper who holds the science kind; the routes answer 403 and write nothing', function () {
    $science = makeItem(['name_th' => 'วัสดุวิทยาศาสตร์ยืม', 'category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);
    $scienceLot = makeImsLot($this->lab->id, $science);
    $keeper = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical', 'consumable', 'science']]);
    $admin = adminUser(['lab_id' => $this->lab->id]);

    expect(Gate::forUser($keeper)->allows('transfer', $scienceLot))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('transfer', $scienceLot))->toBeFalse()
        // Sanity: the same keeper may still move a chemical lot.
        ->and(Gate::forUser($keeper)->allows('transfer', $this->chemLot))->toBeTrue();

    $this->actingAs($keeper)->get(route('ims.lots.transfer.form', $scienceLot))->assertForbidden();
    $this->actingAs($keeper)->post(route('ims.lots.transfer', $scienceLot), ($this->payload)())->assertForbidden();

    expect((float) $scienceLot->fresh()->qty_remaining_base)->toEqual(2000.0)
        ->and(Container::where('item_id', $science->id)->count())->toBe(0)
        ->and(StockLedger::count())->toBe(0)
        ->and(ImsMovement::where('ims_lot_id', $scienceLot->id)->where('type', 'ISSUE')->count())->toBe(0);
});
