<?php

use App\Mail\NotificationMail;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('FR-NT-02: a container expiring in exactly 30 days notifies item.manage and disposal.request holders', function () {
    Mail::fake();
    $labManager = labManagerUser();
    // disposal.request moved off SCIENTIST on 2026-09-22; ADMIN now holds it.
    $admin = adminUser();
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(30)->toDateString()]);

    $this->artisan('notifications:check-expiry')->assertSuccessful();

    expect(Notification::where('user_id', $labManager->id)->where('type', 'stock.expiry')->exists())->toBeTrue();
    expect(Notification::where('user_id', $admin->id)->where('type', 'stock.expiry')->exists())->toBeTrue();
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->body !== null && str_contains($mail->body, $container->barcode));
});

test('a container expiring in 45 days (not one of 90/30/7) is not notified yet', function () {
    Mail::fake();
    labManagerUser();
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(45)->toDateString()]);

    $this->artisan('notifications:check-expiry')->assertSuccessful();

    expect(Notification::where('type', 'stock.expiry')->exists())->toBeFalse();
});

test('a DISPOSED container at one of the thresholds is not notified', function () {
    Mail::fake();
    labManagerUser();
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'DISPOSED', 'expiry_date' => now()->addDays(7)->toDateString()]);

    $this->artisan('notifications:check-expiry')->assertSuccessful();

    expect(Notification::where('type', 'stock.expiry')->exists())->toBeFalse();
});
