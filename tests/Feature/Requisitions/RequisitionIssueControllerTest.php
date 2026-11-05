<?php

use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user without requisition.issue gets 403 on the issue page, even in the requisition\'s own branch', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $approverOnly = userWithPermissions(['requisition.approve_scientist', 'requisition.view_own'], ['lab_id' => $requisition->lab_id]);

    $this->actingAs($approverOnly)->get(route('requisitions.issue.create', $requisition))->assertStatus(403);
});

test('approvals.md K: a warehouse manager of the own branch (and kind) can open the issue page; ADMIN and a manager with no branch cannot', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');

    $this->actingAs(labManagerUser(['lab_id' => $requisition->lab_id]))->get(route('requisitions.issue.create', $requisition))->assertOk();
    $this->actingAs(auditorUser(['lab_id' => $requisition->lab_id]))->get(route('requisitions.issue.create', $requisition))->assertOk();
    $this->actingAs(labManagerUser(['lab_id' => null]))->get(route('requisitions.issue.create', $requisition))->assertForbidden();
    $this->actingAs(adminUser(['lab_id' => $requisition->lab_id]))->get(route('requisitions.issue.create', $requisition))->assertForbidden();
});

test('the issue page is not reachable for a DRAFT requisition', function () {
    $staff = staffUser();
    $requisition = makeRequisition($staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertStatus(403);
});

test('user-requested 2026-09-21: the issue page warns when an item\'s stock has dropped below its reorder point', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $line->item()->update(['reorder_point_base' => '100.000000']);
    stockedContainer($line->item_id, '50.000000', $staff, $requisition->lab_id); // below the 100 reorder point
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.low_stock_warning', ['balance' => '50']));
});

test('the issue page does not warn when stock is still above the reorder point', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $line->item()->update(['reorder_point_base' => '10.000000']);
    stockedContainer($line->item_id, '50.000000', $staff, $requisition->lab_id); // above the 10 reorder point
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))
        ->assertOk()
        ->assertDontSee(__('requisitions.low_stock_warning', ['balance' => '50']));
});

test('a scientist can record a full issue through issue-all', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '20.000000', 'unit_id' => $g->id]],
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('ISSUED');
    expect($container->fresh()->remaining_qty_base)->toBe('30.000000');
});

test('issue-all draws from multiple containers in one submission, no barcode needed', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '2000.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '1000.000000', $staff, $requisition->lab_id);
    stockedContainer($line->item_id, '1000.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '2000.000000', 'unit_id' => $g->id]],
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('ISSUED');
    expect($line->fresh()->qty_issued_base)->toBe('2000.000000');
    expect(\App\Models\IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(2);
});

test('ITM-2610-062: when working stock falls short the whole line is refused (no partial issue), nothing moves and the status stays', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '2000.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '500.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '2000.000000', 'unit_id' => $g->id]],
    ])->assertRedirect(route('requisitions.issue.create', $requisition))->assertSessionHasErrors('issue');

    // Before the rule change this issued the 500 g that existed and left the requisition PARTIALLY_ISSUED.
    expect($requisition->fresh()->status)->toBe('APPROVED');
    expect(bccomp((string) ($line->fresh()->qty_issued_base ?? '0'), '0', 6))->toBe(0);
    expect($container->fresh()->remaining_qty_base)->toBe('500.000000');
    expect(\App\Models\IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(0);
});

test('a container with no storage place is not usable working stock: the line is refused', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);   // no place at all
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '20', 'unit_id' => $g->id]],
    ])->assertSessionHasErrors('issue');

    expect($requisition->fresh()->status)->toBe('APPROVED');
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
});

test('issue-all with no containers in stock at all reports an error, not a 500', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '100.000000', 'unit_id' => $g->id]],
    ])->assertSessionHasErrors('issue');
});

test('the issue page shows the quantity to dispense in big tiles and prefills the form with it', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '120.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '50.000000', $staff, $requisition->lab_id)->update(['location_id' => makeLocationForLab(\App\Models\Lab::findOrFail($requisition->lab_id))->id]);
    $line->update(['qty_issued_base' => '20.000000']);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $page = $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertOk();
    $page->assertSee(__('requisitions.tile_requested'))->assertSee(__('requisitions.tile_issued'))->assertSee(__('requisitions.tile_to_issue'))
        ->assertSee('120')->assertSee('100')                      // asked 120, issued 20 -> 100 still to dispense
        ->assertSee('value="100"', false)                         // the dispense form is prefilled with what is still owed
        ->assertSee(__('requisitions.issue_progress', ['done' => 0, 'total' => 1]))
        ->assertSee(__('requisitions.stock_short'));              // only 50 g available against 100 g to dispense
});

test('a fully dispensed line shows nothing left to issue', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $line->update(['qty_issued_base' => '20.000000']);
    $requisition->update(['status' => 'ISSUED']);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertOk()
        ->assertSee(__('requisitions.issue_progress', ['done' => 1, 'total' => 1]));
});

test('one submission dispenses every line of the requisition with a single signature', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '300.000000');
    $first = $requisition->items->first();
    $requisition->update(['status' => 'DRAFT']);
    $second = app(\App\Domain\Requisition\Services\RequisitionService::class)
        ->addLine($requisition, makeItem(), Unit::where('code', 'g')->firstOrFail(), '200.000000');
    $requisition->update(['status' => 'APPROVED']);
    stockedContainer($first->item_id, '1000.000000', $staff, $requisition->lab_id);
    stockedContainer($second->item_id, '1000.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [
            $first->id => ['qty' => '300', 'unit_id' => $g->id],
            $second->id => ['qty' => '200', 'unit_id' => $g->id],
        ],
    ])->assertRedirect(route('requisitions.issue.create', $requisition))->assertSessionHas('status');

    expect($first->fresh()->qty_issued_base)->toBe('300.000000');
    expect($second->fresh()->qty_issued_base)->toBe('200.000000');
    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('issue-all skips a line left at 0 and reports a line that cannot be issued', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '300.000000');
    $first = $requisition->items->first();
    $requisition->update(['status' => 'DRAFT']);
    $second = app(\App\Domain\Requisition\Services\RequisitionService::class)
        ->addLine($requisition, makeItem(), Unit::where('code', 'g')->firstOrFail(), '200.000000');
    $requisition->update(['status' => 'APPROVED']);
    stockedContainer($first->item_id, '1000.000000', $staff, $requisition->lab_id); // second item has no stock at all
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [
            $first->id => ['qty' => '300', 'unit_id' => $g->id],
            $second->id => ['qty' => '200', 'unit_id' => $g->id],
        ],
    ])->assertSessionHasErrors('issue');

    expect($first->fresh()->qty_issued_base)->toBe('300.000000');
    expect(bccomp((string) ($second->fresh()->qty_issued_base ?? '0'), '0', 6))->toBe(0);

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$second->id => ['qty' => '0', 'unit_id' => $g->id]],
    ])->assertSessionHasErrors('issue');
});

test('issue-all needs the issue permission', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $g = Unit::where('code', 'g')->firstOrFail();
    $payload = ['lines' => [$line->id => ['qty' => '50', 'unit_id' => $g->id]]];

    $this->actingAs(labManagerUser())->post(route('requisitions.issue-all', $requisition), $payload)->assertStatus(403);

});

test('ITM-2610-062: when working stock is short the issue page shows the "not enough in working stock" box, and the link to the paper-requisition move only for those who may move that kind', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $manager = labManagerUser(['lab_id' => $requisition->lab_id]);                 // keeps both kinds
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);                // may dispense, may not move IMS stock
    \App\Models\ImsLot::create([
        'item_id' => $line->item_id, 'lab_id' => $requisition->lab_id, 'lot_no' => 'L1',
        'qty_received_base' => '500.000000', 'qty_remaining_base' => '500.000000', 'location_id' => null, 'received_at' => now(),
    ]);
    stockedContainer($line->item_id, '20.000000', $staff, $requisition->lab_id);   // 20 g in working stock, 100 g needed

    $page = $this->actingAs($manager)->get(route('requisitions.issue.create', $requisition))->assertOk();
    $page->assertSee(__('working_stock.issue_short_title'))
        ->assertSee(__('working_stock.issue_short_desc'))
        ->assertSee(__('working_stock.issue_short_row', ['need' => '100', 'working' => '20', 'ims' => '500', 'unit' => 'g']))
        ->assertSee(route('ims.transfers.create'), false)
        ->assertSee(__('working_stock.btn_go_transfer'))
        ->assertDontSee(__('requisitions.btn_set_location'));

    // A scientist sees the explanation but no link to a page they cannot use.
    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertOk()
        ->assertSee(__('working_stock.issue_short_title'))
        ->assertDontSee(route('ims.transfers.create'), false)
        ->assertDontSee(__('working_stock.btn_go_transfer'));
});

test('the "not enough" box is absent when working stock covers the line, and a keeper of the OTHER kind gets no link', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '10.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '50.000000', $staff, $requisition->lab_id);

    $this->actingAs(labManagerUser(['lab_id' => $requisition->lab_id]))->get(route('requisitions.issue.create', $requisition))->assertOk()
        ->assertDontSee(__('working_stock.issue_short_title'))->assertDontSee(__('working_stock.btn_go_transfer'));

    // Short again (needs 100 g of a chemical): a manager who keeps only consumables cannot even open the page (per-kind rule),
    // so the link is never offered to them.
    $short = approvedRequisition($staff, '100.000000');
    $consumableOnly = auditorUser(['lab_id' => $short->lab_id, 'itemTypes' => ['consumable']]);
    $this->actingAs($consumableOnly)->get(route('requisitions.issue.create', $short))->assertForbidden();
});

test('issue-all can name another person from the same branch as the receiver', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '500.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $colleague = \App\Models\User::factory()->create(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '50', 'unit_id' => $g->id]],
        'receiver_id' => $colleague->id,
    ])->assertSessionHas('status');

    expect(\App\Models\IssueTransaction::where('requisition_item_id', $line->id)->value('receiver_id'))->toBe($colleague->id);
});

test('issue-all rejects a receiver from another branch', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '500.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $stranger = \App\Models\User::factory()->create(['lab_id' => makeLab()->id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '50', 'unit_id' => $g->id]],
        'receiver_id' => $stranger->id,
    ])->assertSessionHasErrors('receiver_id');

    expect($line->fresh()->qty_issued_base)->toBeIn([null, '0.000000']);
});

test('issue-all requires an explicit confirmation when a controlled substance is dispensed', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $line->item()->update(['is_controlled' => true]);
    stockedContainer($line->item_id, '500.000000', $staff, $requisition->lab_id);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $payload = ['lines' => [$line->id => ['qty' => '50', 'unit_id' => $g->id]]];

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), $payload)->assertSessionHasErrors('controlled_ack');
    expect($line->fresh()->qty_issued_base)->toBeIn([null, '0.000000']);

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), $payload + ['controlled_ack' => '1'])->assertSessionHas('status');
    expect($line->fresh()->qty_issued_base)->toBe('50.000000');
});
