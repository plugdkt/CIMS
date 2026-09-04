<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-NT-01/02: spec's own "Daily 07:00".
Schedule::command('notifications:check-reorder')->dailyAt('07:00');
Schedule::command('notifications:check-expiry')->dailyAt('07:00');
// FR-NT-05: spec says "Daily" with no time — same 07:00 batch as the other daily checks.
Schedule::command('notifications:check-shelf-life')->dailyAt('07:00');
// FR-NT-06: spec says "Immediate", but no real trigger point exists (see the command's own
// docblock) — hourly is the closest practical stand-in for a periodic integrity scan.
Schedule::command('notifications:check-hash-chain')->hourly();
