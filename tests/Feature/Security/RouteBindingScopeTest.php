<?php

// ITM-2610-039: nested route bindings must be scoped — a {requisition_item} in the URL has to
// belong to the {requisition} in the same URL, otherwise the Policy is checked against one
// document while the action runs on another (IDOR).

use App\Models\IssueTransaction;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Requisition A (attacker's branch X, status ISSUED so the Policy passes) and requisition B
 * (branch $labB, fully issued from one container).
 *
 * @return array{scientist: \App\Models\User, a: Requisition, b: Requisition, lineB: RequisitionItem, container: \App\Models\Container, g: Unit}
 */
function returnScopeFixture(bool $sameBranch): array
{
    $labX = makeLab();
    $labB = $sameBranch ? $labX : makeLab();
    $g = Unit::where('code', 'g')->firstOrFail();

    $staffA = staffUser(['lab_id' => $labX->id]);
    $a = approvedRequisition($staffA, '10.000000');
    $a->update(['status' => 'ISSUED']);

    $staffB = staffUser(['lab_id' => $labB->id]);
    $b = approvedRequisition($staffB, '50.000000');
    $lineB = $b->items->first();
    $container = stockedContainer($lineB->item_id, '100.000000', $staffB);
    $scientistB = scientistUser(['lab_id' => $labB->id]);
    app(\App\Domain\Requisition\Services\IssueService::class)
        ->issue($lineB, $container, '50.000000', $g, $scientistB, $staffB, hash('sha256', 'x'));

    return [
        'scientist' => scientistUser(['lab_id' => $labX->id]),
        'a' => $a->fresh(),
        'b' => $b->fresh(),
        'lineB' => $lineB->fresh(),
        'container' => $container->fresh(),
        'g' => $g,
    ];
}

function assertReturnRejectedAndNothingChanged(array $f): void
{
    $ledgerBefore = StockLedger::count();
    $issuesBefore = IssueTransaction::count();
    $remainingBefore = $f['container']->fresh()->remaining_qty_base;
    $returnedBefore = $f['lineB']->fresh()->qty_returned_base;

    $response = test()->actingAs($f['scientist'])->post(route('requisitions.items.return', [$f['a'], $f['lineB']]), [
        'container_id' => $f['container']->id,
        'qty_returned' => '20.000000',
        'unit_id' => $f['g']->id,
    ]);

    // 404 exactly: the parent passes its Policy, so only the new ownership guard can refuse.
    expect($response->getStatusCode())->toBe(404);
    expect($f['container']->fresh()->remaining_qty_base)->toBe($remainingBefore);
    expect($f['lineB']->fresh()->qty_returned_base)->toBe($returnedBefore);
    expect(StockLedger::count())->toBe($ledgerBefore);
    expect(IssueTransaction::count())->toBe($issuesBefore);
}

test('return: a line of another requisition in the SAME branch cannot be returned via this requisition URL', function () {
    assertReturnRejectedAndNothingChanged(returnScopeFixture(sameBranch: true));
});

test('return: a line of a requisition in ANOTHER branch cannot be returned via this requisition URL', function () {
    assertReturnRejectedAndNothingChanged(returnScopeFixture(sameBranch: false));
});

test('destroy line: a line of another requisition cannot be deleted via a DRAFT requisition URL', function () {
    $lab = makeLab();
    $requester = staffUser(['lab_id' => $lab->id]);
    $draftA = makeRequisition($requester); // DRAFT, owned by the actor
    $other = approvedRequisition(staffUser(['lab_id' => makeLab()->id]), '10.000000');
    $otherLine = $other->items->first();

    $response = $this->actingAs($requester)->delete(route('requisitions.items.destroy', [$draftA, $otherLine]));

    // 404 exactly: the parent passes its Policy, so only the new ownership guard can refuse.
    expect($response->getStatusCode())->toBe(404);
    expect(RequisitionItem::whereKey($otherLine->id)->exists())->toBeTrue();
});

test('destroy GRN line: a line of another goods receipt cannot be deleted via a DRAFT GRN URL (ITM-2610-041)', function () {
    $lab = makeLab();
    $auditor = auditorUser(['lab_id' => $lab->id]);
    $grnA = makeDraftGrn(['lab_id' => $lab->id]);
    $grnB = makeDraftGrn(['lab_id' => makeLab()->id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $lineB = app(\App\Domain\Inventory\Services\GoodsReceiptService::class)
        ->addLine($grnB, makeItem(), $g, 1, '5.000000');

    $response = $this->actingAs($auditor)->delete(route('goods-receipts.items.destroy', [$grnA, $lineB]));

    // 404 exactly: the parent passes its Policy, so only the new ownership guard can refuse.
    expect($response->getStatusCode())->toBe(404);
    expect(\App\Models\GoodsReceiptItem::whereKey($lineB->id)->exists())->toBeTrue();
});

test('destroy GRN line: the matching pair still works (ITM-2610-041)', function () {
    $lab = makeLab();
    $auditor = auditorUser(['lab_id' => $lab->id]);
    $grn = makeDraftGrn(['lab_id' => $lab->id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $line = app(\App\Domain\Inventory\Services\GoodsReceiptService::class)
        ->addLine($grn, makeItem(), $g, 1, '5.000000');

    $this->actingAs($auditor)->delete(route('goods-receipts.items.destroy', [$grn, $line]))
        ->assertRedirect(route('goods-receipts.show', $grn));

    expect(\App\Models\GoodsReceiptItem::whereKey($line->id)->exists())->toBeFalse();
});

test('destroy line: the matching requisition/line pair still deletes the row (ITM-2610-041)', function () {
    $requester = staffUser(['lab_id' => makeLab()->id]);
    $draft = makeRequisition($requester);
    $line = app(\App\Domain\Requisition\Services\RequisitionService::class)
        ->addLine($draft, makeItem(), Unit::where('code', 'g')->firstOrFail(), '10.000000');

    $this->actingAs($requester)->delete(route('requisitions.items.destroy', [$draft, $line]))
        ->assertRedirect(route('requisitions.show', $draft));

    expect(RequisitionItem::whereKey($line->id)->exists())->toBeFalse();
});
