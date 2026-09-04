<?php

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Requisition\Services\ApprovalService;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\RequisitionService;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * T-038: spec §11.2's own numbered feature tests, run verbatim with spec's own numbers
 * where it gives them (FT-02's "500 g / 12.5 g / 487.5 g", FT-04's "100 mL / 60 mL").
 *
 * FT-01 already lives in ApprovalServiceTest.php ("FT-01: the scientist cannot approve a
 * STUDENT requisition with no advisor sign-off yet (BR-02)"), FT-06 in
 * ScientistDecisionTest.php, FT-09 in FefoContainerSelectorTest.php, FT-10 in
 * Fr03ExportTest.php (T-025) — not duplicated here.
 *
 * FT-05 (return), FT-07 (stocktake variance → adjustment), and FT-08's HTTP-403 layer
 * genuinely cannot be written yet: they exercise Return (T-040), Stock Take (T-041), and
 * the Adjustment workflow's HTTP/Policy layer (T-043) — none of which exist as features
 * in this phase. `LedgerService::return()`/`adjust()` (the underlying primitives, T-021)
 * are already tested in LedgerServiceTest.php, including BR-06's same-actor rejection —
 * but the actual REQUISITION-integrated return flow and the STOCKTAKE-triggered variance
 * detection are Phase 4 features. Writing a shallow test against only the primitive would
 * not exercise what FT-05/07/08 actually describe, so — same as T-017's ST-04 — these are
 * deferred to the tasks that build the real target, not guessed at now.
 */
test('FT-02: submit → advisor approves → scientist approves → issue 12.5 g from a 500 g bottle', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requisitionService = app(RequisitionService::class);
    $requisitionService->addLine($requisition, $item, $g, '12.5');
    $requisitionService->submit($requisition);

    $approvalService = app(ApprovalService::class);
    $approvalService->advisorDecide($requisition->fresh(), $requisition->advisor, 'APPROVE');
    $approvalService->scientistDecide($requisition->fresh(), scientistUser(), 'APPROVE');

    $container = stockedContainer($item->id, '500.000000', $student);
    $line = $requisition->fresh(['items'])->items->first();
    app(IssueService::class)->issue($line, $container, '12.5', $g, scientistUser(), $student, hash('sha256', 'ft-02'));

    expect($container->fresh()->remaining_qty_base)->toBe('487.500000');
    expect(StockLedger::where('item_id', $item->id)->where('txn_type', 'ISSUE')->count())->toBe(1);
    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('FT-03: issuing more than the container holds throws InsufficientStockException and writes no new ledger row', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '10.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    $ledgerRowsBefore = StockLedger::where('item_id', $line->item_id)->count();

    expect(fn () => app(IssueService::class)->issue($line, $container, '20.000000', $g, $scientist, $staff, hash('sha256', 'ft-03')))
        ->toThrow(InsufficientStockException::class);

    expect(StockLedger::where('item_id', $line->item_id)->count())->toBe($ledgerRowsBefore);
});

test('FT-04: requesting 100 mL and issuing 60 mL leaves the requisition PARTIALLY_ISSUED', function () {
    $staff = staffUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'mL')->value('id')]);
    $requisition = makeRequisition($staff);
    $mL = Unit::where('code', 'mL')->firstOrFail();
    $requisitionService = app(RequisitionService::class);
    $requisitionService->addLine($requisition, $item, $mL, '100');
    $requisition->update(['status' => 'APPROVED']);

    $container = stockedContainer($item->id, '1000.000000', $staff);
    $line = $requisition->fresh(['items'])->items->first();
    $scientist = scientistUser();

    app(IssueService::class)->issue($line, $container, '60', $mL, $scientist, $staff, hash('sha256', 'ft-04'));

    expect($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED');
});
