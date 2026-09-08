<?php

use App\Models\LedgerSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('T-047: ledger:snapshot with an explicit month generates a snapshot for that period', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    snapshotLedgerRow($item->id, $container->id, $user->id, '2026-08-10', '10.000000', '0.000000', '10.000000');

    $this->artisan('ledger:snapshot', ['month' => '2026-08'])
        ->assertExitCode(0)
        ->expectsOutputToContain('2026-08');

    expect(LedgerSnapshot::where('item_id', $item->id)->where('period_ym', '2569-08')->exists())->toBeTrue();
});

test('T-047: an invalid month argument fails cleanly instead of throwing', function () {
    $this->artisan('ledger:snapshot', ['month' => 'not-a-month'])
        ->assertExitCode(1)
        ->expectsOutputToContain('รูปแบบเดือนไม่ถูกต้อง');
});

test('T-047: ledger:snapshot with no argument defaults to last calendar month', function () {
    $item = makeItem();
    $container = makeContainer(['item_id' => $item->id]);
    $user = User::factory()->create();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
    snapshotLedgerRow($item->id, $container->id, $user->id, $lastMonth->copy()->addDays(2)->toDateString(), '10.000000', '0.000000', '10.000000');

    $this->artisan('ledger:snapshot')->assertExitCode(0);

    $expectedPeriodYm = ($lastMonth->year + 543).'-'.$lastMonth->format('m');
    expect(LedgerSnapshot::where('item_id', $item->id)->where('period_ym', $expectedPeriodYm)->exists())->toBeTrue();
});
