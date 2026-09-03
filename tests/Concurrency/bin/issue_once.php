<?php

declare(strict_types=1);

/**
 * T-026 (CT-01/CT-02) support script — NOT a shipped app feature, never registered as an
 * Artisan command. A single Pest test process is single-threaded, so calling
 * LedgerService::issue() in a loop inside one test would never exercise the row-locking
 * this is meant to verify (every call would trivially serialize). This script is spawned
 * as a separate OS process (see tests/Feature/Ledger/ConcurrencyTest.php), each with its
 * own DB connection, so the container's `lockForUpdate()` row lock is genuinely contended.
 *
 * Usage: php issue_once.php <container_id> <qty_base> <unit_code> <user_id> [repeat=1]
 * Prints one line per attempt: "OK" or "INSUFFICIENT". Exits 0 regardless (failures are
 * an expected outcome under contention, not a script error) unless something unexpected
 * throws, in which case it prints "ERROR: ..." to stderr and exits 2.
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[$containerId, $qtyBase, $unitCode, $userId] = [
    (int) $argv[1], (string) $argv[2], (string) $argv[3], (int) $argv[4],
];
$repeat = isset($argv[5]) ? (int) $argv[5] : 1;

$unit = App\Models\Unit::where('code', $unitCode)->firstOrFail();
$service = app(App\Domain\Inventory\Services\LedgerService::class);

try {
    for ($i = 0; $i < $repeat; $i++) {
        try {
            $service->issue($containerId, $qtyBase, new App\Domain\Inventory\DTO\LedgerEntryData(
                displayUnitId: $unit->id,
                createdBy: $userId,
            ));
            fwrite(STDOUT, "OK\n");
        } catch (App\Domain\Inventory\Exceptions\InsufficientStockException) {
            fwrite(STDOUT, "INSUFFICIENT\n");
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage()."\n");
    exit(2);
}
