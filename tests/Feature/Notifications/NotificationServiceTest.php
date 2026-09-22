<?php

use App\Domain\Notification\Services\NotificationService;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('notifyInApp writes a notification row and sends no email', function () {
    Mail::fake();
    $user = User::factory()->create();

    $notification = app(NotificationService::class)->notifyInApp($user, 'test.type', 'หัวข้อ', 'เนื้อหา', '/somewhere');

    expect($notification->user_id)->toBe($user->id);
    expect($notification->type)->toBe('test.type');
    expect($notification->read_at)->toBeNull();
    expect(Notification::count())->toBe(1);
    Mail::assertNothingSent();
});

test('notifyInAppAndEmail writes a notification row and queues an email to the user', function () {
    Mail::fake();
    $user = User::factory()->create();

    app(NotificationService::class)->notifyInAppAndEmail($user, 'test.type', 'หัวข้อ', 'เนื้อหา', '/somewhere');

    expect(Notification::count())->toBe(1);
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->title === 'หัวข้อ');
});

test('emailOnly sends an email but writes no notification row', function () {
    Mail::fake();
    $user = User::factory()->create();

    app(NotificationService::class)->emailOnly($user, 'หัวข้อ', 'เนื้อหา', null);

    expect(Notification::count())->toBe(0);
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($user->email));
});

test('notifyInAppAndEmail writes in-app notification but skips email when user email is empty', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => '']);

    $notification = app(NotificationService::class)->notifyInAppAndEmail($user, 'test.type', 'หัวข้อ', 'เนื้อหา', '/somewhere');

    expect(Notification::count())->toBe(1);
    expect($notification->user_id)->toBe($user->id);
    Mail::assertNothingQueued();
});

test('emailOnly skips email when user email is empty', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => '']);

    app(NotificationService::class)->emailOnly($user, 'หัวข้อ', 'เนื้อหา', null);

    expect(Notification::count())->toBe(0);
    Mail::assertNothingQueued();
});

test('usersWithAnyPermission returns active users holding at least one of the given permissions, deduplicated', function () {
    $labManager = labManagerUser(); // item.manage, disposal.request, ledger.adjust, ...
    $admin = adminUser(); // disposal.request only, since 2026-09-22
    $inactive = labManagerUser();
    $inactive->update(['is_active' => false]);

    $recipients = app(NotificationService::class)->usersWithAnyPermission('item.manage', 'disposal.request');

    expect($recipients->pluck('id')->all())->toContain($labManager->id, $admin->id);
    expect($recipients->pluck('id')->all())->not->toContain($inactive->id);
    expect($recipients->pluck('id')->unique()->count())->toBe($recipients->count());
});

test('usersWithRole returns only active users holding that role', function () {
    $admin = adminUser();
    $inactiveAdmin = adminUser();
    $inactiveAdmin->update(['is_active' => false]);
    $scientist = scientistUser();

    $recipients = app(NotificationService::class)->usersWithRole('ADMIN');

    expect($recipients->pluck('id')->all())->toContain($admin->id);
    expect($recipients->pluck('id')->all())->not->toContain($inactiveAdmin->id, $scientist->id);
});
