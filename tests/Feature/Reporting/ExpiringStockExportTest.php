<?php

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Exports\ExpiringStockExport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('§7.8 lists active containers whose expiry_date falls in the given range', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(10)->toDateString()]);
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(60)->toDateString()]);

    $rows = (new ExpiringStockExport(new DateRangeFilter(dateFrom: now()->toDateString(), dateTo: now()->addDays(30)->toDateString())))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][0])->toBe($item->name_th);
});

test('a DISPOSED container is excluded even if its expiry_date is in range', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'DISPOSED', 'expiry_date' => now()->addDays(10)->toDateString()]);

    $rows = (new ExpiringStockExport(new DateRangeFilter(dateFrom: now()->toDateString(), dateTo: now()->addDays(30)->toDateString())))->collection();

    expect($rows)->toHaveCount(0);
});

test('a container with no expiry_date is never listed', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'expiry_date' => null]);

    $rows = (new ExpiringStockExport(new DateRangeFilter()))->collection();

    expect($rows)->toHaveCount(0);
});

test('free-text lot_no is CSV-injection guarded', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'status' => 'IN_USE', 'lot_no' => '=1+1', 'expiry_date' => now()->addDays(5)->toDateString()]);

    $rows = (new ExpiringStockExport(new DateRangeFilter()))->collection();

    expect($rows[0][3])->toBe("'=1+1");
});
