<?php

use App\Domain\Loan\Services\LoanLedgerHasher;
use App\Mail\NotificationMail;
use App\Models\LoanMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 §6: `loans:verify` (scheduled hourly with --notify) checks every asset's hash chain, that the
 * asset's totals equal its latest movement, and that no adjust / replace row lacks a different approver.
 * Exit code 1 when anything is wrong. The approver check cannot be exercised by an INSERT because the database
 * CHECK (chk_lm_approval) refuses such a row first; the CHECK itself is tested in LoanLedgerDatabaseTest.
 * No table is dropped here (that would break the rest of the suite): the "no tables yet" branch is not tested.
 */

test('a healthy ledger passes with exit code 0 and says how many assets were checked', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    registerLoanAsset($manager, $lab, 'UNIT');
    loanService()->adjust($manager, $asset->fresh(), 'IN', 2, 'นับใหม่พบเพิ่มสองชิ้น', null, $approver->id);

    $this->artisan('loans:verify')
        ->expectsOutputToContain(__('loans.verify_cmd.ok', ['count' => 2]))
        ->assertExitCode(0);
});

test('an empty ledger passes', function () {
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('a hand-edited loan_assets quantity is caught (totals no longer equal the latest movement)', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_total' => 12]);   // allowed by the grants; the ledger still says 10

    $this->artisan('loans:verify')
        ->expectsOutputToContain(__('loans.verify_cmd.broken', ['problem' => "asset #{$asset->id}: quantities differ"]))
        ->assertExitCode(1);
});

test('hand-edited on-loan and unavailable counters are caught too', function (string $column) {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    DB::table('loan_assets')->where('id', $asset->id)->update([$column => 3]);

    $this->artisan('loans:verify')->expectsOutputToContain('quantities differ')->assertExitCode(1);
})->with(['qty_on_loan', 'qty_unavailable']);

test('a broken hash chain is caught and names the row (simulated by appending a row with a wrong hash)', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $first = LoanMovement::where('loan_asset_id', $asset->id)->firstOrFail();
    $forged = new LoanMovement([
        'loan_asset_id' => $asset->id, 'lab_id' => $lab->id, 'movement_type' => 'HANDOUT', 'qty' => '1.000000',
        'qty_total_after' => '10.000000', 'qty_on_loan_after' => '0.000000', 'qty_unavailable_after' => '0.000000',
        'created_by' => $manager->id, 'created_at' => now(), 'prev_row_hash' => $first->row_hash, 'row_hash' => str_repeat('f', 64),
    ]);
    $forged->save();

    $this->artisan('loans:verify')
        ->expectsOutputToContain(__('loans.verify_cmd.broken', ['problem' => "asset #{$asset->id}: chain broken at movement id={$forged->id}"]))
        ->assertExitCode(1);
});

test('a chain whose link to the previous row is wrong is caught even if each row hashes correctly', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    $orphan = new LoanMovement([
        'loan_asset_id' => $asset->id, 'lab_id' => $lab->id, 'movement_type' => 'HANDOUT', 'qty' => '1.000000',
        'qty_total_after' => '10.000000', 'qty_on_loan_after' => '0.000000', 'qty_unavailable_after' => '0.000000',
        'created_by' => $manager->id, 'created_at' => now(), 'prev_row_hash' => str_repeat('1', 64),   // not the previous row's hash
    ]);
    $orphan->row_hash = app(LoanLedgerHasher::class)->compute($orphan);
    $orphan->save();

    $this->artisan('loans:verify')->expectsOutputToContain('chain broken')->assertExitCode(1);
});

test('an asset with no movement at all is reported', function () {
    [$lab, $manager] = loanManagers();
    DB::table('loan_assets')->insert([
        'ulid' => (string) \Illuminate\Support\Str::ulid(), 'lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL',
        'qty_total' => 5, 'qty_on_loan' => 0, 'qty_unavailable' => 0, 'status' => 'ACTIVE',
    ]);

    $this->artisan('loans:verify')->expectsOutputToContain('no movement at all')->assertExitCode(1);
    expect($manager->id)->not->toBeNull();
});

test('--notify emails every ADMIN when something is wrong, and no one when all is well', function () {
    Mail::fake();
    $adminOne = adminUser();
    $adminTwo = adminUser();
    $manager = auditorUser(['lab_id' => makeLab()->id]);   // a non-admin: must not be mailed
    $asset = registerLoanAsset($manager);

    $this->artisan('loans:verify', ['--notify' => true])->assertExitCode(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_total' => 12]);

    $this->artisan('loans:verify', ['--notify' => true])->expectsOutputToContain(__('loans.verify_cmd.notified'))->assertExitCode(1);

    foreach ([$adminOne, $adminTwo] as $admin) {
        $sent = Mail::sent(NotificationMail::class, fn ($mail) => $mail->hasTo($admin->email))->count()
            + Mail::queued(NotificationMail::class, fn ($mail) => $mail->hasTo($admin->email))->count();
        expect($sent)->toBe(1, "ADMIN {$admin->id} must get exactly one mail");
    }
    $toManager = Mail::sent(NotificationMail::class, fn ($mail) => $mail->hasTo($manager->email))->count()
        + Mail::queued(NotificationMail::class, fn ($mail) => $mail->hasTo($manager->email))->count();
    expect($toManager)->toBe(0);
});

test('without --notify a problem is reported on screen only, nobody is mailed', function () {
    Mail::fake();
    adminUser();
    $asset = registerLoanAsset(auditorUser(['lab_id' => makeLab()->id]));
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_total' => 12]);

    $this->artisan('loans:verify')->assertExitCode(1);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('the command is scheduled hourly with --notify', function () {
    \Illuminate\Support\Facades\Artisan::call('schedule:list');   // makes sure routes/console.php has registered its schedule
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'loans:verify'));

    expect($events)->toHaveCount(1);
    expect($events->first()->command)->toContain('--notify');
    expect($events->first()->expression)->toBe('0 * * * *');
});
