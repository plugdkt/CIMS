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
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertStatus(403);
});

test('a scientist can record a full issue via barcode and a drawn signature', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '20.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '50.000000', $staff);
    $scientist = scientistUser();
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
    $scientist = scientistUser();
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
    $scientist = scientistUser();
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
    $scientist = scientistUser();
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
    $scientist = scientistUser();

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
    $scientist = scientistUser();
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
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($scientist)->post(route('requisitions.items.issue', [$requisition, $line]), [
        'barcode' => $container->barcode,
        'qty_issued' => '20.000000',
        'unit_id' => $g->id,
        'signature_image' => TEST_PNG_DATA_URL,
    ])->assertSessionHasErrors('issue');

    expect($requisition->fresh()->status)->toBe('APPROVED');
});
