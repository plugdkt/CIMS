<?php

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\Exports\ItemStockSummaryExport;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('itemStockSummaryLedgerRow')) {
    function itemStockSummaryLedgerRow(int $itemId, string $txnType, string $qtyIn, string $qtyOut, string $balance, ?string $txnDate = null): StockLedger
    {
        return StockLedger::create([
            'item_id' => $itemId,
            'txn_date' => $txnDate ?? now()->toDateString(),
            'txn_type' => $txnType,
            'qty_in_base' => $qtyIn,
            'qty_out_base' => $qtyOut,
            'balance_base' => $balance,
            'display_unit_id' => Unit::where('code', 'g')->value('id'),
            'created_by' => User::factory()->create()->id,
            'created_at' => now(),
            'prev_row_hash' => null,
            'row_hash' => bin2hex(random_bytes(32)),
        ]);
    }
}

test('§7.8 item stock summary lists an item\'s used quantity and current balance', function () {
    $item = makeItem();
    itemStockSummaryLedgerRow($item->id, 'RECEIVE', '100.000000', '0', '100.000000');
    itemStockSummaryLedgerRow($item->id, 'ISSUE', '0', '30.000000', '70.000000');

    $rows = (new ItemStockSummaryExport(new DateRangeFilter()))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][0])->toBe($item->item_code);
    expect($rows[0][1])->toBe($item->name_th);
    expect($rows[0][2])->toBe('30.000000 g');
    expect($rows[0][3])->toBe('70.000000 g');
});

test('an item with no ledger history at all is not listed', function () {
    makeItem();

    expect((new ItemStockSummaryExport(new DateRangeFilter()))->collection())->toHaveCount(0);
});

test('rows are sorted lowest current balance first', function () {
    $high = makeItem(['name_th' => 'สารคงเหลือเยอะ']);
    itemStockSummaryLedgerRow($high->id, 'RECEIVE', '500.000000', '0', '500.000000');

    $low = makeItem(['name_th' => 'สารคงเหลือน้อย']);
    itemStockSummaryLedgerRow($low->id, 'RECEIVE', '10.000000', '0', '10.000000');

    $rows = (new ItemStockSummaryExport(new DateRangeFilter()))->collection();

    expect($rows)->toHaveCount(2);
    expect($rows[0][1])->toBe('สารคงเหลือน้อย');
    expect($rows[1][1])->toBe('สารคงเหลือเยอะ');
});

test('the used quantity only counts ISSUE rows inside the given date range', function () {
    $item = makeItem();
    itemStockSummaryLedgerRow($item->id, 'RECEIVE', '100.000000', '0', '100.000000', now()->subDays(60)->toDateString());
    itemStockSummaryLedgerRow($item->id, 'ISSUE', '0', '10.000000', '90.000000', now()->subDays(40)->toDateString());
    itemStockSummaryLedgerRow($item->id, 'ISSUE', '0', '5.000000', '85.000000', now()->subDays(2)->toDateString());

    $recent = (new ItemStockSummaryExport(new DateRangeFilter(dateFrom: now()->subDays(10)->toDateString())))->collection();

    expect($recent[0][2])->toBe('5.000000 g');
    expect($recent[0][3])->toBe('85.000000 g');
});

test('filtering by lab only includes items with a container physically in that lab', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locationA = makeLocationForLab($labA);
    $locationB = makeLocationForLab($labB);

    $itemA = makeItem(['name_th' => 'สารสาขา A']);
    makeContainer(['item_id' => $itemA->id, 'location_id' => $locationA->id]);
    itemStockSummaryLedgerRow($itemA->id, 'RECEIVE', '20.000000', '0', '20.000000');

    $itemB = makeItem(['name_th' => 'สารสาขา B']);
    makeContainer(['item_id' => $itemB->id, 'location_id' => $locationB->id]);
    itemStockSummaryLedgerRow($itemB->id, 'RECEIVE', '20.000000', '0', '20.000000');

    $rows = (new ItemStockSummaryExport(new DateRangeFilter(), $labA->id))->collection();

    expect($rows)->toHaveCount(1);
    expect($rows[0][1])->toBe('สารสาขา A');
});
