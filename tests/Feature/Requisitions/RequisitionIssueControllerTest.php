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

test('the issue page lets a scientist click a container row to select it, and trims trailing zeros from displayed quantities', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '500.000000', $staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $response = $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition));

    $response->assertOk();
    // A click-to-select radio per container row, wired to the same barcode field a
    // physical scanner still types into (x-model, not a plain static `value`).
    $response->assertSee('x-model="selectedBarcode"', false);
    $response->assertSee("selectedBarcode: '{$container->barcode}'", false);
    $response->assertSee(__('requisitions.select_container_to_issue'));
    // Quantities are DECIMAL(18,6) internally but shouldn't show all six decimals —
    // "500.000000" should never appear in the rendered page, only the trimmed "500".
    expect($response->getContent())->not->toContain('500.000000');
    $response->assertSee('500', false);
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

test('user-requested 2026-09-23: the issue page shows both the auto and manual modes', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.issue_mode_auto'))
        ->assertSee(__('requisitions.issue_mode_manual'))
        ->assertSee(__('requisitions.field_qty_issued_total'));
});
