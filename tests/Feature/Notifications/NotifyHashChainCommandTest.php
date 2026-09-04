<?php

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

test('FR-NT-06: an intact chain sends no email and reports success', function () {
    Mail::fake();
    adminUser();
    $item = makeItem();
    $unit = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    appendLedgerRow($item->id, $unit->id, $user->id, '10.000000', '10.000000');

    $this->artisan('notifications:check-hash-chain')->assertExitCode(0);

    Mail::assertNothingSent();
    expect(Notification::count())->toBe(0); // FR-NT-06 is email-only, no in-app row
});

test('FR-NT-06: a broken chain emails every ADMIN and no one else', function () {
    Mail::fake();
    $admin1 = adminUser();
    $admin2 = adminUser();
    $scientist = scientistUser();
    $item = makeItem();
    $unit = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $good = appendLedgerRow($item->id, $unit->id, $user->id, '10.000000', '10.000000');

    $tampered = new StockLedger([
        'item_id' => $item->id,
        'txn_date' => now()->toDateString(),
        'txn_type' => 'RECEIVE',
        'qty_in_base' => '2.000000',
        'qty_out_base' => '0.000000',
        'balance_base' => '12.000000',
        'display_unit_id' => $unit->id,
        'created_by' => $user->id,
        'created_at' => now(),
        'prev_row_hash' => $good->row_hash,
        'row_hash' => str_repeat('0', 64),
    ]);
    $tampered->save();

    $this->artisan('notifications:check-hash-chain')->assertExitCode(1);

    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($admin1->email));
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($admin2->email));
    Mail::assertNotQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($scientist->email));
    expect(Notification::count())->toBe(0);
});
