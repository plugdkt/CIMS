<?php

// ITM-2610-035 / ITM-2610-036 — permission-boundary tests from the ITM-2610-033 coverage matrix.
//
// (1) ADMIN must be refused on every ledger-writing route except the legacy /goods-receipts one,
//     where the CEO's E7 answer ("ADMIN ทำได้": receive goods) makes the ledger write indirect-by-design.
// (2) controlled_ack (controlled substances) is enforced on issue-all, the only issue path. The per-line
//     routes (items.issue / items.issue-auto) that bypassed it were removed in ITM-2610-036; the tests
//     below prove they are gone and that nothing can cut stock through them.

use App\Models\Disposal;
use App\Models\ImsReceipt;
use App\Models\IssueTransaction;
use App\Models\StockLedger;
use App\Models\StockTake;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/* ------------------------------------------------------------------ *
 | (1) ADMIN vs ledger-writing routes
 * ------------------------------------------------------------------ */

test('E7: ADMIN confirming a legacy goods receipt DOES write RECEIVE ledger rows (indirect, by design)', function () {
    $admin = adminUser();
    $grn = makeDraftGrn(['received_by' => $admin->id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Inventory\Services\GoodsReceiptService::class)->addLine($grn, makeItem(), $g, 1, '10');

    $this->actingAs($admin)->post(route('goods-receipts.confirm', $grn))->assertRedirect();

    expect($grn->fresh()->status)->toBe('CONFIRMED');
    $rows = StockLedger::where('ref_type', 'GRN')->where('ref_id', $grn->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->created_by)->toBe($admin->id);
});

test('ADMIN is refused (403) on IMS receipt store and confirm', function () {
    $lab = makeLab();
    $admin = adminUser(['lab_id' => $lab->id]);
    $receipt = ImsReceipt::create(['lab_id' => $lab->id, 'status' => 'DRAFT', 'created_by' => $admin->id]);

    $this->actingAs($admin)->post(route('ims.receipts.store'), [])->assertForbidden();
    $this->actingAs($admin)->post(route('ims.receipts.confirm', $receipt), ['lines' => [1]])->assertForbidden();
    expect($receipt->fresh()->status)->toBe('DRAFT');
});

test('ADMIN is refused (403) on creating a stock adjustment, and no ledger row appears', function () {
    $admin = adminUser();
    $container = stockedContainer(makeItem()->id, '50.000000', $admin);
    $before = StockLedger::count();

    $this->actingAs($admin)->post(route('adjustments.store'), [
        'barcode' => $container->barcode,
        'direction' => 'OUT',
        'qty' => '5',
        'remark' => 'ทดสอบสิทธิ์ปรับปรุงยอด',
        'approved_by' => $admin->id,
    ])->assertForbidden();

    expect(StockLedger::count())->toBe($before);
});

test('ADMIN is refused (403) on approving or rejecting a disposal', function () {
    $admin = adminUser();
    $container = stockedContainer(makeItem()->id, '50.000000', $admin);
    $disposal = Disposal::create([
        'doc_no' => 'DSP-TEST-0001', 'container_id' => $container->id, 'qty_base' => '5.000000',
        'reason' => 'EXPIRED', 'disposal_date' => now()->toDateString(), 'requested_by' => $admin->id, 'status' => 'PENDING',
    ]);

    $this->actingAs($admin)->post(route('disposals.approve', $disposal))->assertForbidden();
    $this->actingAs($admin)->post(route('disposals.reject', $disposal))->assertForbidden();
    expect($disposal->fresh()->status)->toBe('PENDING');
});

test('ADMIN is refused (403) on approving a stock take', function () {
    $lab = makeLab();
    $admin = adminUser(['lab_id' => $lab->id]);
    $take = StockTake::create([
        'doc_no' => 'ST-TEST-0001', 'lab_id' => $lab->id, 'count_date' => now()->toDateString(),
        'status' => 'PENDING_APPROVAL', 'created_by' => $admin->id,
    ]);

    $this->actingAs($admin)->post(route('stock-takes.approve', $take))->assertForbidden();
    expect($take->fresh()->status)->toBe('PENDING_APPROVAL');
});

test('ADMIN is refused (403) on requisition issue (issue-all, the only path) and return', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $admin = adminUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($admin)->post(route('requisitions.issue-all', $requisition), ['lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]]])->assertForbidden();

    $requisition->update(['status' => 'ISSUED']);
    $this->actingAs($admin)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id, 'qty_returned' => '1', 'unit_id' => $g->id,
    ])->assertForbidden();

    expect(IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(0);
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
});


/* ------------------------------------------------------------------ *
 | (2) controlled_ack: issue-all is the only issue path
 * ------------------------------------------------------------------ */

test('control: a NON-controlled item can be issued through issue-all (proves the setup works)', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]],
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
});

test('issue-all must NOT cut stock for a controlled substance without controlled_ack', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $line->item()->update(['is_controlled' => true]);
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $payload = ['lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]]];

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), $payload)->assertSessionHasErrors('controlled_ack');

    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
    expect(IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(0);

    // Same request with the tick-box DOES issue (so the refusal above is really the ack check).
    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), $payload + ['controlled_ack' => '1'])->assertSessionHas('status');
    expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
});

/**
 * ITM-2610-038: returns of controlled substances need controlled_ack like issues do.
 * Sets up an issued line (50 g from a 100 g container) and returns [scientist, requisition, line, container, g].
 */
function issuedLineForReturn(bool $controlled): array
{
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $line->item()->update(['is_controlled' => $controlled]);
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(\App\Domain\Requisition\Services\IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));

    return [$scientist, $requisition, $line, $container, $g];
}

test('return of a controlled substance without controlled_ack is refused and writes nothing', function () {
    [$scientist, $requisition, $line, $container, $g] = issuedLineForReturn(true);
    $before = $container->fresh()->remaining_qty_base;
    $txBefore = IssueTransaction::where('requisition_item_id', $line->id)->count();
    $ledgerBefore = StockLedger::count();

    $this->actingAs($scientist)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id, 'qty_returned' => '20', 'unit_id' => $g->id,
    ])->assertSessionHasErrors('controlled_ack');

    expect($container->fresh()->remaining_qty_base)->toBe($before);
    expect(IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe($txBefore);
    expect(StockLedger::count())->toBe($ledgerBefore);
    expect($line->fresh()->qty_returned_base)->toBe('0.000000');
});

test('return of a controlled substance with controlled_ack succeeds', function () {
    [$scientist, $requisition, $line, $container, $g] = issuedLineForReturn(true);

    $this->actingAs($scientist)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id, 'qty_returned' => '20', 'unit_id' => $g->id, 'controlled_ack' => '1',
    ])->assertSessionHasNoErrors()->assertSessionHas('status');

    expect($container->fresh()->remaining_qty_base)->toBe('70.000000');
    expect($line->fresh()->qty_returned_base)->toBe('20.000000');
});

test('return of a NON-controlled item still succeeds without controlled_ack', function () {
    [$scientist, $requisition, $line, $container, $g] = issuedLineForReturn(false);

    $this->actingAs($scientist)->post(route('requisitions.items.return', [$requisition, $line]), [
        'container_id' => $container->id, 'qty_returned' => '20', 'unit_id' => $g->id,
    ])->assertSessionHasNoErrors()->assertSessionHas('status');

    expect($container->fresh()->remaining_qty_base)->toBe('70.000000');
});

test('the per-line issue routes no longer exist: POST is 404/405 and the stock does not move', function (string $suffix, array $extra) {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $line->item()->update(['is_controlled' => true]);
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    expect(Route::has('requisitions.items.issue'))->toBeFalse();
    expect(Route::has('requisitions.items.issue-auto'))->toBeFalse();
    expect(Route::has('requisitions.receiver-otp.send'))->toBeFalse();

    $url = route('requisitions.show', $requisition).str_replace('{line}', (string) $line->id, $suffix);
    $status = $this->actingAs($scientist)->post($url, $extra + [
        'barcode' => $container->barcode, 'qty_issued' => '5', 'unit_id' => $g->id,
    ])->getStatusCode();

    expect($status)->toBeIn([404, 405]);
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
    expect(IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(0);
})->with([
    'items.issue' => ['/items/{line}/issue', []],
    'items.issue-auto' => ['/items/{line}/issue-auto', []],
    'receiver-otp' => ['/receiver-otp', []],
]);
