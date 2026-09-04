<?php

use App\Domain\Inventory\Services\FefoContainerSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('BR-03: IN_USE sorts before SEALED regardless of expiry', function () {
    $item = makeItem();
    $sealed = makeContainer(['item_id' => $item->id, 'status' => 'SEALED', 'remaining_qty_base' => '10', 'expiry_date' => now()->addDay()]);
    $inUse = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => now()->addYear()]);

    $result = app(FefoContainerSelector::class)->recommend($item);

    expect($result->first()->id)->toBe($inUse->id);
    expect($result->last()->id)->toBe($sealed->id);
});

test('BR-03: within the same status, the soonest expiry_date is recommended first', function () {
    $item = makeItem();
    $far = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => now()->addYear()]);
    $soon = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => now()->addDay()]);

    $result = app(FefoContainerSelector::class)->recommend($item);

    expect($result->first()->id)->toBe($soon->id);
    expect($result->last()->id)->toBe($far->id);
});

test('BR-03: when expiry_date is NULL for both, the oldest received_at is recommended first', function () {
    $item = makeItem();
    $newer = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => null, 'received_at' => now()->subDays(2)]);
    $older = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => null, 'received_at' => now()->subDays(30)]);

    $result = app(FefoContainerSelector::class)->recommend($item);

    expect($result->first()->id)->toBe($older->id);
    expect($result->last()->id)->toBe($newer->id);
});

test('a container with a known expiry_date is recommended before one with none, in the same status tier', function () {
    $item = makeItem();
    $noExpiry = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => null]);
    $knownExpiry = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => now()->addYear()]);

    $result = app(FefoContainerSelector::class)->recommend($item);

    expect($result->first()->id)->toBe($knownExpiry->id);
    expect($result->last()->id)->toBe($noExpiry->id);
});

test('EMPTY, DISPOSED, QUARANTINE, and zero-remaining containers are never recommended', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'EMPTY', 'remaining_qty_base' => '0']);
    makeContainer(['item_id' => $item->id, 'status' => 'DISPOSED', 'remaining_qty_base' => '10']);
    makeContainer(['item_id' => $item->id, 'status' => 'QUARANTINE', 'remaining_qty_base' => '10']);
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '0']);
    $eligible = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10']);

    $result = app(FefoContainerSelector::class)->recommend($item);

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($eligible->id);
});

test('BR-03: an already-expired container is excluded from recommendExcludingExpired but flagged by isExpired', function () {
    $item = makeItem();
    $expired = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => now()->subDay()]);
    $fresh = makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'remaining_qty_base' => '10', 'expiry_date' => now()->addYear()]);

    $selector = app(FefoContainerSelector::class);

    expect($selector->isExpired($expired))->toBeTrue();
    expect($selector->isExpired($fresh))->toBeFalse();

    $recommended = $selector->recommendExcludingExpired($item);
    expect($recommended)->toHaveCount(1);
    expect($recommended->first()->id)->toBe($fresh->id);
});
