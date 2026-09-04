<?php

use App\Domain\Notification\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user sees only their own notifications on the index page', function () {
    $me = scientistUser();
    $someoneElse = scientistUser();
    $service = app(NotificationService::class);
    $service->notifyInApp($me, 'test.type', 'ของฉัน', null, null);
    $service->notifyInApp($someoneElse, 'test.type', 'ของคนอื่น', null, null);

    $response = $this->actingAs($me)->get(route('notifications.index'));

    $response->assertOk()->assertSee('ของฉัน')->assertDontSee('ของคนอื่น');
});

test('opening a notification marks it read and redirects to its link', function () {
    $me = scientistUser();
    $notification = app(NotificationService::class)->notifyInApp($me, 'test.type', 'หัวข้อ', null, '/items');

    $this->actingAs($me)->post(route('notifications.read', $notification))
        ->assertRedirect('/items');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('a user cannot mark someone else\'s notification as read (IDOR)', function () {
    $me = scientistUser();
    $someoneElse = scientistUser();
    $notification = app(NotificationService::class)->notifyInApp($someoneElse, 'test.type', 'หัวข้อ', null, null);

    $this->actingAs($me)->post(route('notifications.read', $notification))->assertStatus(403);
    expect($notification->fresh()->read_at)->toBeNull();
});

test('mark-all-read clears every unread notification for the current user only', function () {
    $me = scientistUser();
    $someoneElse = scientistUser();
    $service = app(NotificationService::class);
    $mine = $service->notifyInApp($me, 'test.type', 'หัวข้อ 1', null, null);
    $mine2 = $service->notifyInApp($me, 'test.type', 'หัวข้อ 2', null, null);
    $theirs = $service->notifyInApp($someoneElse, 'test.type', 'หัวข้อ 3', null, null);

    $this->actingAs($me)->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));

    expect($mine->fresh()->read_at)->not->toBeNull();
    expect($mine2->fresh()->read_at)->not->toBeNull();
    expect($theirs->fresh()->read_at)->toBeNull();
});
