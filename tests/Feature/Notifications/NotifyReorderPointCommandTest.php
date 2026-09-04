<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('FR-NT-01: an item whose balance dropped below its reorder point notifies every item.manage holder', function () {
    Mail::fake();
    $labManager = labManagerUser();
    $item = makeItem(['reorder_point_base' => '10.000000']);
    $g = Unit::where('code', 'g')->firstOrFail();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '5.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $labManager->id));

    $this->artisan('notifications:check-reorder')->assertSuccessful();

    expect(Notification::where('user_id', $labManager->id)->where('type', 'stock.reorder')->exists())->toBeTrue();
    Mail::assertQueued(NotificationMail::class);
});

test('an item whose balance is still above its reorder point is not notified', function () {
    Mail::fake();
    $labManager = labManagerUser();
    $item = makeItem(['reorder_point_base' => '10.000000']);
    $g = Unit::where('code', 'g')->firstOrFail();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $labManager->id));

    $this->artisan('notifications:check-reorder')->assertSuccessful();

    expect(Notification::where('type', 'stock.reorder')->exists())->toBeFalse();
});

test('an item with the default zero reorder point is never flagged', function () {
    Mail::fake();
    labManagerUser();
    makeItem(); // reorder_point_base defaults to 0, no containers/stock at all

    $this->artisan('notifications:check-reorder')->assertSuccessful();

    expect(Notification::where('type', 'stock.reorder')->exists())->toBeFalse();
});
