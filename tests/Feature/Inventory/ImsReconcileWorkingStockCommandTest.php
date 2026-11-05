<?php

use App\Domain\Requisition\Services\RequisitionService;
use App\Domain\Shared\ItemType;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
 * ITM-2610-062: `ims:reconcile-working-stock` is a READ-ONLY report for the days before cut-over. These tests check that
 * it never writes, and that each of its four sections finds the case it exists for.
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->otherLocation = makeLocationForLab($this->otherLab);
    $this->g = Unit::where('code', 'g')->firstOrFail();

    $this->report = function (?string $lab = null): string {
        $code = Artisan::call('ims:reconcile-working-stock', $lab === null ? [] : ['--lab' => $lab]);
        expect($code)->toBe(0);

        return Artisan::output();
    };

    // Everything the report must never change.
    $this->snapshot = fn () => [
        'lots' => ImsLot::count(),
        'lot_qty' => ImsLot::orderBy('id')->pluck('qty_remaining_base')->map(fn ($q) => (string) $q)->all(),
        'containers' => Container::count(),
        'container_qty' => Container::orderBy('id')->pluck('remaining_qty_base')->map(fn ($q) => (string) $q)->all(),
        'ledger' => StockLedger::count(),
        'movements' => ImsMovement::count(),
        'audit' => AuditLog::count(),
        'notifications' => Notification::count(),
        'requisitions' => Requisition::orderBy('id')->pluck('status')->all(),
    ];

    $this->requisition = function (int $labId, $item, string $qty, string $status = 'APPROVED'): Requisition {
        $requisition = makeRequisition(staffUser(['lab_id' => $labId]), ['request_type' => [ItemType::requestTypeValue(ItemType::forItem($item))]]);
        app(RequisitionService::class)->addLine($requisition, $item, $this->g, $qty);
        $requisition->update(['status' => $status]);

        return $requisition->fresh(['items']);
    };
});

test('the report is read-only: running it (all branches, one branch) changes no lot, container, ledger, movement, audit, notification or requisition', function () {
    $item = makeItem();
    makeImsLot($this->lab->id, $item, '2', '500');
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '300.000000']);
    makeContainer(['item_id' => $item->id, 'location_id' => null, 'remaining_qty_base' => '50.000000']);
    ($this->requisition)($this->lab->id, makeItem(), '10');
    labManagerUser(['lab_id' => $this->lab->id]);

    $before = ($this->snapshot)();

    ($this->report)();
    ($this->report)($this->lab->code);
    ($this->report)((string) $this->lab->id);

    expect(($this->snapshot)())->toBe($before);
});

test('section 1 compares IMS with working stock per item, flags "IMS only" and "both", and sums with BCMath (no float drift)', function () {
    $both = makeItem(['name_th' => 'สารมีสองที่', 'item_code' => 'CHM-REC01']);
    $imsOnly = makeItem(['name_th' => 'สารมีแต่คลังกลาง', 'item_code' => 'CHM-REC02']);
    $decimal = makeItem(['name_th' => 'สารเศษทศนิยม', 'item_code' => 'CHM-REC03']);
    $noIms = makeItem(['name_th' => 'สารมีแต่คลังใช้งาน', 'item_code' => 'CHM-REC04']);

    makeImsLot($this->lab->id, $both, '2', '500');                                         // 1000
    makeContainer(['item_id' => $both->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '300.000000']);
    makeImsLot($this->lab->id, $imsOnly, '1', '500');                                       // 500
    makeImsLot($this->lab->id, $decimal, '1', null, ['qty_remaining_base' => '0.100000', 'qty_received_base' => '0.100000']);
    makeImsLot($this->lab->id, $decimal, '1', null, ['qty_remaining_base' => '0.200000', 'qty_received_base' => '0.200000']);
    makeContainer(['item_id' => $noIms->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '70.000000']);
    // Another branch's lot and a container that is used up never show up here.
    makeImsLot($this->otherLab->id, $imsOnly, '3', '500');
    makeContainer(['item_id' => $both->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '0.000000', 'status' => 'EMPTY']);

    $out = ($this->report)($this->lab->code);

    expect($out)->toContain(__('ims_reconcile.readonly_note'))
        ->toContain('CHM-REC01')->toContain('1000.000000')->toContain('300.000000')->toContain(__('ims_reconcile.flag_both'))
        ->toContain('CHM-REC02')->toContain('500.000000')->toContain(__('ims_reconcile.flag_ims_only'))
        ->toContain('CHM-REC03')->toContain('0.300000')
        ->not->toContain('CHM-REC04')        // nothing left in IMS: not part of the comparison
        ->not->toContain('2000.000000');     // the other branch's 3 packs are not added in

    // "both" is flagged only where working stock really holds something.
    expect(preg_match('/CHM-REC02[^\n]*'.preg_quote(__('ims_reconcile.flag_ims_only'), '/').'/u', $out))->toBe(1);
    expect(preg_match('/CHM-REC01[^\n]*'.preg_quote(__('ims_reconcile.flag_both'), '/').'/u', $out))->toBe(1);
});

test('section 1 only counts containers whose place belongs to the branch and that are SEALED or IN_USE', function () {
    $item = makeItem(['item_code' => 'CHM-REC05']);
    makeImsLot($this->lab->id, $item, '1', '500');

    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '10.000000', 'status' => 'SEALED']);
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '20.000000', 'status' => 'IN_USE']);
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '40.000000', 'status' => 'QUARANTINE']);
    makeContainer(['item_id' => $item->id, 'location_id' => $this->otherLocation->id, 'remaining_qty_base' => '80.000000']);
    makeContainer(['item_id' => $item->id, 'location_id' => null, 'remaining_qty_base' => '320.000000']);

    $out = ($this->report)($this->lab->code);

    // 10 + 20 only.
    expect(preg_match('/CHM-REC05[^\n]*500\.000000[^\n]*30\.000000/u', $out))->toBe(1);
});

test('section 2 finds containers with no place, a place of no branch, or a branch different from their source lot', function () {
    $item = makeItem();
    $lot = makeImsLot($this->lab->id, $item, '4', '500');

    // normal: place in the lot's own branch
    makeContainer(['item_id' => $item->id, 'ims_lot_id' => $lot->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '500.000000', 'barcode' => 'BC-OK-1']);
    // odd: no place / a place of no branch removed (locations.lab_id is NOT NULL)
    makeContainer(['item_id' => $item->id, 'ims_lot_id' => $lot->id, 'location_id' => null, 'remaining_qty_base' => '50.000000', 'barcode' => 'BC-ODD-1']);
    // odd, but for the OTHER branch's report: the place is theirs, the source lot is ours
    makeContainer(['item_id' => $item->id, 'ims_lot_id' => $lot->id, 'location_id' => $this->otherLocation->id, 'remaining_qty_base' => '70.000000', 'barcode' => 'BC-ODD-3']);
    // not reported: used up
    makeContainer(['item_id' => $item->id, 'ims_lot_id' => $lot->id, 'location_id' => null, 'remaining_qty_base' => '0.000000', 'status' => 'EMPTY', 'barcode' => 'BC-GONE']);

    $own = ($this->report)($this->lab->code);
    expect($own)->toContain(__('ims_reconcile.containers_count', ['count' => 1, 'sample' => 'BC-ODD-1']))
        ->not->toContain('BC-OK-1')->not->toContain('BC-ODD-3')->not->toContain('BC-GONE');

    $other = ($this->report)($this->otherLab->code);
    expect($other)->toContain(__('ims_reconcile.containers_count', ['count' => 1, 'sample' => 'BC-ODD-3']));
});

test('section 2 says "none found" for a clean branch', function () {
    $item = makeItem();
    $lot = makeImsLot($this->lab->id, $item, '1', '500');
    makeContainer(['item_id' => $item->id, 'ims_lot_id' => $lot->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '500.000000']);

    expect(($this->report)($this->lab->code))->toContain('  '.__('ims_reconcile.containers_none'));
});

test('section 3: short lines are counted per kind (documents and lines), covered / no-IMS / wrong-status ones are not', function () {
    $chemShort = makeItem(['item_code' => 'CHM-REQ01']);
    $chemShort2 = makeItem(['item_code' => 'CHM-REQ09']);
    $chemCovered = makeItem(['item_code' => 'CHM-REQ02']);
    $chemNoIms = makeItem(['item_code' => 'CHM-REQ03']);
    $supplyShort = consumableItem(['item_code' => 'MAT-REQ01']);
    makeImsLot($this->lab->id, $chemShort, '1', '500');
    makeImsLot($this->lab->id, $chemShort2, '1', '500');
    makeImsLot($this->lab->id, $chemCovered, '1', '500');
    makeContainer(['item_id' => $chemCovered->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '300.000000']);
    makeImsLot($this->lab->id, $supplyShort, '4', '100');

    // Document 1 (chemical): two short lines -> 1 document, 2 lines
    $doc1 = makeRequisition(staffUser(['lab_id' => $this->lab->id]));
    app(RequisitionService::class)->addLine($doc1, $chemShort, $this->g, '100');
    app(RequisitionService::class)->addLine($doc1, $chemShort2, $this->g, '100');
    $doc1->update(['status' => 'APPROVED']);

    // Document 2 (chemical): PARTIALLY_ISSUED, 400 asked of which 100 issued -> 300 left, working has 0 -> counted
    $doc2 = ($this->requisition)($this->lab->id, $chemShort, '400', 'PARTIALLY_ISSUED');
    $doc2->items->first()->update(['qty_issued_base' => '100.000000']);

    // Not counted: working covers it (200 <= 300), no IMS stock to move, still SUBMITTED, other branch, fully issued line
    ($this->requisition)($this->lab->id, $chemCovered, '200');
    ($this->requisition)($this->lab->id, $chemNoIms, '100');
    ($this->requisition)($this->lab->id, $chemShort, '100', 'SUBMITTED');
    ($this->requisition)($this->otherLab->id, $chemShort, '100');
    $done = ($this->requisition)($this->lab->id, $chemShort, '100');
    $done->items->first()->update(['qty_issued_base' => '100.000000']);

    // Document 3 (consumable)
    ($this->requisition)($this->lab->id, $supplyShort, '50');

    $out = ($this->report)($this->lab->code);

    // "สารเคมี | 2 | 3" = chemical: documents 1 and 2, three lines. "วัสดุสิ้นเปลือง | 1 | 1".
    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_chemical'), '/').'\s*\|\s*2\s*\|\s*3\s*\|/u', $out))->toBe(1, $out);
    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_consumable'), '/').'\s*\|\s*1\s*\|\s*1\s*\|/u', $out))->toBe(1, $out);
});

test('section 3 says "none found" when nothing is stuck', function () {
    $item = makeItem();
    makeImsLot($this->lab->id, $item, '1', '500');
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '500.000000']);
    ($this->requisition)($this->lab->id, $item, '100');

    expect(($this->report)($this->lab->code))->toContain('  '.__('ims_reconcile.requisitions_none'));
});

test('section 4 warns when a kind has no keeper or more than one in the branch, ignoring ADMIN and inactive accounts', function () {
    makeImsLot($this->lab->id, makeItem(), '1', '500');    // the section is shown for branches that hold IMS stock

    // chemical: two active keepers (+ an inactive one and an ADMIN with a row, who must not count) -> "many"
    labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);
    auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);
    labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical'], 'is_active' => false]);
    $admin = adminUser(['lab_id' => $this->lab->id]);
    grantItemTypes($admin, ['chemical', 'consumable']);
    // a keeper of another branch does not count here
    labManagerUser(['lab_id' => $this->otherLab->id, 'itemTypes' => ['consumable']]);
    // consumable: nobody -> "none"

    $out = ($this->report)($this->lab->code);

    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_chemical'), '/').'\s*\|\s*2\s*\|\s*'.preg_quote(__('ims_reconcile.warn_many_managers'), '/').'/u', $out))->toBe(1, $out);
    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_consumable'), '/').'\s*\|\s*0\s*\|\s*'.preg_quote(__('ims_reconcile.warn_no_manager'), '/').'/u', $out))->toBe(1, $out);
});

test('section 4 is "ok" with exactly one keeper per kind', function () {
    makeImsLot($this->lab->id, makeItem(), '1', '500');
    labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);
    labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['consumable']]);

    $out = ($this->report)($this->lab->code);

    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_chemical'), '/').'\s*\|\s*1\s*\|\s*'.preg_quote(__('ims_reconcile.ok'), '/').'/u', $out))->toBe(1, $out);
    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_consumable'), '/').'\s*\|\s*1\s*\|\s*'.preg_quote(__('ims_reconcile.ok'), '/').'/u', $out))->toBe(1, $out);
});

test('a branch with no IMS stock gets no keeper warnings (nothing to move there)', function () {
    $out = ($this->report)($this->lab->code);

    expect($out)->not->toContain(__('ims_reconcile.warn_no_manager'))
        ->toContain(__('ims_reconcile.compare_none'));
});

test('--lab accepts the branch code or its id; an unknown branch fails and prints a message', function () {
    makeImsLot($this->lab->id, makeItem(['item_code' => 'CHM-LAB01']), '1', '500');
    makeImsLot($this->otherLab->id, makeItem(['item_code' => 'CHM-LAB02']), '1', '500');

    $byCode = ($this->report)($this->lab->code);
    $byId = ($this->report)((string) $this->lab->id);
    $all = ($this->report)();

    expect($byCode)->toContain('CHM-LAB01')->not->toContain('CHM-LAB02')
        ->and($byId)->toContain('CHM-LAB01')->not->toContain('CHM-LAB02')
        ->and($all)->toContain('CHM-LAB01')->toContain('CHM-LAB02');

    expect(Artisan::call('ims:reconcile-working-stock', ['--lab' => 'NO-SUCH-LAB']))->toBe(1);
    expect(Artisan::output())->toContain(__('ims_reconcile.lab_not_found', ['lab' => 'NO-SUCH-LAB']));
});

// --- science (loan-and-return), CEO 2026-11-05 (ITM-2610-063) --------------------------------------------------------

test('section 1 leaves science (loan) items out: their IMS stock is lent, not moved into working stock', function () {
    $chem = makeItem(['item_code' => 'CHM-SCI01', 'name_th' => 'สารคู่เทียบ']);
    $sci = scienceItem(['item_code' => 'SCI-REC01', 'name_th' => 'วัสดุยืมคืนในรายงาน']);
    makeImsLot($this->lab->id, $chem, '2', '500');
    makeImsLot($this->lab->id, $sci, '2', '500');
    makeContainer(['item_id' => $sci->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '40.000000']);

    $out = ($this->report)($this->lab->code);

    expect($out)->toContain('CHM-SCI01')->not->toContain('SCI-REC01')->not->toContain('วัสดุยืมคืนในรายงาน');
});

test('section 1 says "none" when the branch holds only science stock in IMS', function () {
    makeImsLot($this->lab->id, scienceItem(['item_code' => 'SCI-ONLY01']), '2', '500');

    $out = ($this->report)($this->lab->code);

    expect($out)->not->toContain('SCI-ONLY01')->toContain('  '.__('ims_reconcile.compare_none'));
});

test('section 3 reports an unfinished document that has a science line as its OWN row (kind science), and does not throw', function () {
    $sci = makeItem(['item_code' => 'SCI-REC02']);
    makeImsLot($this->lab->id, $sci, '1', '500');
    $doc = ($this->requisition)($this->lab->id, $sci, '100');                 // approved while it was still a normal item
    $sci->update(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);   // ...then moved to science

    $out = ($this->report)($this->lab->code);

    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_science'), '/').'\s*\|\s*1\s*\|\s*1\s*\|/u', $out))->toBe(1, $out);
    expect($doc->fresh()->status)->toBe('APPROVED');
});

test('section 3 keeps counting chemical and consumable documents next to the science row', function () {
    $chem = makeItem(['item_code' => 'CHM-REQ21']);
    $sci = makeItem(['item_code' => 'SCI-REQ21']);
    makeImsLot($this->lab->id, $chem, '1', '500');
    makeImsLot($this->lab->id, $sci, '1', '500');
    ($this->requisition)($this->lab->id, $chem, '100');
    ($this->requisition)($this->lab->id, $sci, '100');
    $sci->update(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);

    $out = ($this->report)($this->lab->code);

    expect(preg_match('/'.preg_quote(__('ims_reconcile.kind_chemical'), '/').'\s*\|\s*1\s*\|\s*1\s*\|/u', $out))->toBe(1, $out)
        ->and(preg_match('/'.preg_quote(__('ims_reconcile.kind_science'), '/').'\s*\|\s*1\s*\|\s*1\s*\|/u', $out))->toBe(1, $out);
});

test('the report with science data is still read-only', function () {
    $sci = scienceItem();
    makeImsLot($this->lab->id, $sci, '2', '500');
    $before = ($this->snapshot)();

    ($this->report)();

    expect(($this->snapshot)())->toBe($before);
});
