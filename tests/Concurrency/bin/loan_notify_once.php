<?php

declare(strict_types=1);

/**
 * ITM-2610-051 step 5 (WP-5.6, NFR-LN-05) support script — NOT a shipped app feature. Same idea as
 * loan_action_once.php: runs ONE `LoanDailyNotifier::runDaily()` (what `php artisan loans:notify-daily` runs) in its own
 * OS process with its own DB connection, released at a common moment, so that tests/Feature/LoansConcurrency/
 * LoanNoticeConcurrencyTest.php can start several at once and show the claim in `loan_notice_log` lets exactly one
 * of them send each notice.
 *
 * Usage: php loan_notify_once.php <start_at>
 *
 * Mail is forced to the `array` driver here, whatever .env says: nothing ever leaves the machine from this script.
 * Prints one line of JSON (what this process really sent, per kind) and exits 0. Anything unexpected prints
 * "ERROR: ..." to stderr and exits 2. Exits 3 without touching anything when the database is not `cmis_testing`.
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');
if ($database !== 'cmis_testing') {
    fwrite(STDERR, "REFUSED TO RUN: this script only works against cmis_testing (got '{$database}').\n");
    exit(3);
}

config(['mail.default' => 'array', 'queue.default' => 'sync']);

$startAt = (float) ($argv[1] ?? 0);

try {
    $notifier = app(App\Domain\Loan\Services\LoanDailyNotifier::class);
    // Open the connection before the start line, so that every process is ready when it is crossed.
    Illuminate\Support\Facades\DB::select('SELECT 1');

    if ($startAt > microtime(true)) {
        time_sleep_until($startAt);
    }

    fwrite(STDOUT, json_encode($notifier->runDaily(), JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e::class.': '.$e->getMessage()."\n");
    exit(2);
}
