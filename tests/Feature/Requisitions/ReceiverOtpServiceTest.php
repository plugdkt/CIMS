<?php

use App\Domain\Requisition\Services\ReceiverOtpService;
use App\Mail\ReceiverOtpMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('a sent OTP verifies correctly and is single-use', function () {
    Mail::fake();
    $staff = staffUser();
    $requisition = makeRequisition($staff);
    $service = app(ReceiverOtpService::class);

    $service->send($staff, $requisition);

    $code = null;
    Mail::assertQueued(ReceiverOtpMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    expect($service->verify($staff, $requisition, $code))->toBeTrue();
    expect($service->verify($staff, $requisition, $code))->toBeFalse();
});

test('a wrong code is rejected', function () {
    Mail::fake();
    $staff = staffUser();
    $requisition = makeRequisition($staff);
    $service = app(ReceiverOtpService::class);

    $service->send($staff, $requisition);

    expect($service->verify($staff, $requisition, '000000'))->toBeFalse();
});

test('a code sent for a different requisition does not verify', function () {
    Mail::fake();
    $staff = staffUser();
    $requisitionA = makeRequisition($staff);
    $requisitionB = makeRequisition($staff);
    $service = app(ReceiverOtpService::class);

    $service->send($staff, $requisitionA);

    $code = null;
    Mail::assertQueued(ReceiverOtpMail::class, function ($mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    expect($service->verify($staff, $requisitionB, $code))->toBeFalse();
});
