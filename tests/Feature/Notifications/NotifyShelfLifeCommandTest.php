<?php

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('FR-NT-05: a container opened longer ago than the item\'s shelf life is flagged in-app only, no email', function () {
    Mail::fake();
    $labManager = labManagerUser();
    $item = makeItem(['shelf_life_days_after_open' => 30]);
    $container = makeContainer([
        'item_id' => $item->id,
        'status' => 'IN_USE',
        'opened_at' => now()->subDays(40)->toDateString(),
    ]);

    $this->artisan('notifications:check-shelf-life')->assertSuccessful();

    $notification = Notification::where('user_id', $labManager->id)->where('type', 'container.shelf_life')->first();
    expect($notification)->not->toBeNull();
    expect($notification->body)->toContain($container->barcode);
    Mail::assertNothingSent();
});

test('a container opened within the shelf life window is not flagged', function () {
    Mail::fake();
    labManagerUser();
    $item = makeItem(['shelf_life_days_after_open' => 30]);
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'opened_at' => now()->subDays(10)->toDateString()]);

    $this->artisan('notifications:check-shelf-life')->assertSuccessful();

    expect(Notification::where('type', 'container.shelf_life')->exists())->toBeFalse();
});

test('a container never opened is not flagged even for an item with a shelf life limit', function () {
    Mail::fake();
    labManagerUser();
    $item = makeItem(['shelf_life_days_after_open' => 30]);
    makeContainer(['item_id' => $item->id, 'status' => 'SEALED', 'opened_at' => null]);

    $this->artisan('notifications:check-shelf-life')->assertSuccessful();

    expect(Notification::where('type', 'container.shelf_life')->exists())->toBeFalse();
});
