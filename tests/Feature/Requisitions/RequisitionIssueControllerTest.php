<?php

use App\Domain\Requisition\Services\ReceiverOtpService;
use App\Mail\ReceiverOtpMail;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('signatures'));

const TEST_PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

test('a user without requisition.issue gets 403 on the issue page', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $labManager = labManagerUser();

    $this->actingAs($labManager)->get(route('requisitions.issue.create', $requisition))->assertStatus(403);
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
    stockedContainer($line->item_id, '50.000000', $staff); // below the 100 reorder point
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
    stockedContainer($line->item_id, '50.000000', $staff); // above the 10 reorder point
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))
        ->assertOk()
        ->assertDontSee(__('requisitions.low_stock_warning', ['balance' => '50']));
});

test('a scientist can record a full issue via barcode and a drawn signature', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => $container->barcode,
        'qty_issued' => '20.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('ISSUED');
    expect($container->fresh()->remaining_qty_base)->toBe('30.000000');
});

test('FR-RQ-11: a receiver can be confirmed via emailed OTP instead of a signature', function () {
    Mail::fake();
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.receiver-otp.send', $requisition))
        ->assertRedirect();
    Mail::assertQueued(ReceiverOtpMail::class, fn ($mail) => $mail->hasTo($staff->email));

    $sentCode = null;
    Mail::assertQueued(ReceiverOtpMail::class, function ($mail) use (&$sentCode) {
        $sentCode = $mail->code;

        return true;
    });

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => $container->barcode,
        'qty_issued' => '20.000000',
        'unit_id' => $g->id,
        'otp_code' => $sentCode,
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('an incorrect OTP is rejected', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    app(ReceiverOtpService::class)->send($staff, $requisition);

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => $container->barcode,
        'qty_issued' => '20.000000',
        'unit_id' => $g->id,
        'otp_code' => '000000',
    ])->assertSessionHasErrors('otp_code');

    expect($requisition->fresh()->status)->toBe('APPROVED');
});

test('neither a signature nor an OTP is rejected by validation', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => $container->barcode,
        'qty_issued' => '20.000000',
        'unit_id' => $g->id,
    ])->assertSessionHasErrors(['signature_image', 'otp_code']);
});

test('an unknown barcode is rejected with a validation error', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => 'BC-NOT-REAL',
        'qty_issued' => '5.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertSessionHasErrors('barcode');
});

test('issuing more than the container holds surfaces InsufficientStockException as a form error', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '10.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => $container->barcode,
        'qty_issued' => '20.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertSessionHasErrors('issue');

    expect($requisition->fresh()->status)->toBe('APPROVED');
});

test('user-requested 2026-09-23: auto-issue draws from multiple containers in one submission, no barcode needed', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '2000.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '1000.000000', $staff);
    stockedContainer($line->item_id, '1000.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $line]), [
        'qty_issued' => '2000.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('ISSUED');
    expect($line->fresh()->qty_issued_base)->toBe('2000.000000');
    expect(\App\Models\IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(2);
});

test('user-requested 2026-09-23: auto-issue reports a partial result when total stock falls short', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '2000.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '500.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $line]), [
        'qty_issued' => '2000.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED');
    expect($line->fresh()->qty_issued_base)->toBe('500.000000');
});

test('auto-issue with no containers in stock at all reports an error, not a 500', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $line]), [
        'qty_issued' => '100.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertSessionHasErrors('issue');
});

test('a user without requisition.issue gets 403 on the auto-issue route', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $labManager = labManagerUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($labManager)->post(route('requisitions.items.issue-auto', [$requisition, $line]), [
        'qty_issued' => '50.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertStatus(403);
});

test('the issue page shows the quantity to dispense in big tiles and prefills the form with it', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '120.000000');
    $line = $requisition->items->first();
    stockedContainer($line->item_id, '50.000000', $staff)->update(['location_id' => makeLocationForLab(\App\Models\Lab::findOrFail($requisition->lab_id))->id]);
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
    stockedContainer($first->item_id, '1000.000000', $staff);
    stockedContainer($second->item_id, '1000.000000', $staff);
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
    stockedContainer($first->item_id, '1000.000000', $staff); // second item has no stock at all
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
