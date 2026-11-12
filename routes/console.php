<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('user:make-admin {username=wittaya.su}', function (string $username = 'wittaya.su') {
    $user = \App\Models\User::where('username', $username)->first();
    if (! $user) {
        $this->error("User {$username} not found. Existing users:");
        foreach (\App\Models\User::all() as $u) {
            $this->line("- {$u->username} ({$u->full_name})");
        }
        return;
    }
    $roles = \App\Models\Role::whereIn('code', ['ADMIN', 'SCIENTIST', 'LAB_MANAGER'])->pluck('id');
    $user->roles()->syncWithoutDetaching($roles);
    $this->info("Assigned roles to {$user->username}: " . $user->fresh()->roles->pluck('code')->join(', '));
})->purpose('Assign ADMIN, SCIENTIST, LAB_MANAGER roles to a user');

// FR-NT-01/02: spec's own "Daily 07:00".
Schedule::command('notifications:check-reorder')->dailyAt('07:00');
Schedule::command('notifications:check-expiry')->dailyAt('07:00');
// FR-NT-05: spec says "Daily" with no time — same 07:00 batch as the other daily checks.
Schedule::command('notifications:check-shelf-life')->dailyAt('07:00');
// FR-NT-06: spec says "Immediate", but no real trigger point exists (see the command's own
// docblock) — hourly is the closest practical stand-in for a periodic integrity scan.
Schedule::command('notifications:check-hash-chain')->hourly();

// ITM-2610-051: integrity check of the lending ledger (hash chain, totals, approvers), emailing ADMIN on a
// problem. Hourly like the stock ledger's check above; nothing in the app can break the chain, so this
// only ever catches changes made outside the application.
Schedule::command('loans:verify --notify')->hourly();

// ITM-2610-051 step 5 (N4-N7): the daily loan notices, 07:00 like the other daily checks. Running it again on the
// same day sends nothing twice (NFR-LN-05: a UNIQUE key in `loan_notice_log` decides, see LoanDailyNotifier);
// withoutOverlapping only spares the wasted second run.
Schedule::command('loans:notify-daily')->dailyAt('07:00')->withoutOverlapping();
// Spec section 14: on the 1st, tell ADMIN / AUDITOR that last month's audit report (write-offs, adjustments) is ready.
Schedule::command('loans:notify-monthly')->monthlyOn(1, '07:30');

// T-047: monthly ledger balance snapshot (performance) — runs early on the 1st, covering
// the calendar month that just closed (the command's own default with no argument).
Schedule::command('ledger:snapshot')->monthlyOn(1, '01:00');
