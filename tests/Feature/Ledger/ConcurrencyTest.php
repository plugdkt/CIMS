<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerHasher;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\User;
use Symfony\Component\Process\Process;

/**
 * T-026 CT-01/CT-02/CT-03 — real concurrency, not simulated.
 *
 * A single Pest test runs single-threaded, so calling LedgerService::issue() in a loop
 * inside one process would never contend the container's lockForUpdate() row lock the way
 * spec's "50 requests พร้อมกัน" (50 simultaneous requests) demands — every call would
 * trivially serialize inside that one process and pass even with a broken lock. These
 * tests instead spawn real separate OS processes (tests/Concurrency/bin/issue_once.php),
 * each opening its own DB connection, genuinely racing for the same container row.
 *
 * That requires committed rows visible across process boundaries, so — unlike every other
 * Feature test in this suite — these tests deliberately do NOT use RefreshDatabase:
 * fixtures are created for real (auto-committed, no wrapping transaction). Pest.php's
 * global Feature beforeEach still runs `$this->seed()`, which is safe here because every
 * seeder uses updateOrCreate (idempotent against already-committed rows).
 *
 * No teardown deletes the rows these tests create: stock_ledger is append-only (AGENT
 * RULE #6, enforced by both the trg_ledger_no_update/no_delete triggers and the T-027 DB
 * grant), and items/containers/users referenced by those ledger rows are held by RESTRICT
 * foreign keys, so they cannot be deleted either. The test item is deactivated
 * (is_active = false) for tidiness; the rest is left as permanent, self-evidently-test
 * data in `cmis_testing` — the same accepted trade-off already documented in CLAUDE.md for
 * T-022's manual dev-DB verification.
 *
 * CT-02 spawns 20 concurrent processes each making 50 sequential issue() calls (0.001 g
 * each = 1,000 attempts total) rather than 1,000 separate OS processes — still genuine
 * cross-process contention on every attempt, at a fraction of the process-spawn cost.
 */
function spawnIssueOnce(int $containerId, string $qtyBase, string $unitCode, int $userId, int $repeat = 1): Process
{
    $process = new Process(
        [
            PHP_BINARY,
            base_path('tests/Concurrency/bin/issue_once.php'),
            (string) $containerId,
            $qtyBase,
            $unitCode,
            (string) $userId,
            (string) $repeat,
        ],
        base_path(),
        ['DB_DATABASE' => 'cmis_testing'],
    );
    $process->setTimeout(120);
    $process->start();

    return $process;
}

/**
 * @param  array<int, Process>  $processes
 * @return array{ok: int, insufficient: int}
 */
function waitAndTally(array $processes): array
{
    $ok = 0;
    $insufficient = 0;

    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException("issue_once.php failed: {$process->getErrorOutput()}");
        }
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if ($line === 'OK') {
                $ok++;
            } elseif ($line === 'INSUFFICIENT') {
                $insufficient++;
            }
        }
    }

    return ['ok' => $ok, 'insufficient' => $insufficient];
}

/** @return array{0: Item, 1: Container, 2: User} */
function concurrencyFixtures(string $unitCode, string $initialQtyBase): array
{
    $category = ItemCategory::where('code', 'CHEMICAL')->firstOrFail();
    $unit = Unit::where('code', $unitCode)->firstOrFail();

    $item = Item::create([
        'item_code' => 'CHM-CT-'.fake()->unique()->numerify('#####'),
        'category_id' => $category->id,
        'name_th' => 'เอทานอล (ทดสอบ concurrency)',
        'name_en' => 'Ethanol (concurrency test)',
        'base_unit_id' => $unit->id,
        'is_active' => true,
    ]);

    $container = Container::create([
        'item_id' => $item->id,
        'barcode' => 'BC-CT-'.fake()->unique()->numerify('########'),
        'received_at' => now()->toDateString(),
        'initial_qty_base' => $initialQtyBase,
        'remaining_qty_base' => '0.000000',
        'status' => 'SEALED',
    ]);

    $user = User::factory()->create();

    app(LedgerService::class)->receive($container->id, $initialQtyBase, new LedgerEntryData(
        displayUnitId: $unit->id,
        createdBy: $user->id,
    ));

    return [$item, $container, $user];
}

test('CT-01: 50 concurrent 10 mL withdrawals from a 400 mL bottle succeed exactly 40 times, fail 10 times, remaining exactly 0', function () {
    [$item, $container, $user] = concurrencyFixtures('mL', '400.000000');

    $processes = [];
    for ($i = 0; $i < 50; $i++) {
        $processes[] = spawnIssueOnce($container->id, '10.000000', 'mL', $user->id);
    }

    $tally = waitAndTally($processes);

    expect($tally['ok'])->toBe(40);
    expect($tally['insufficient'])->toBe(10);

    $fresh = $container->fresh();
    expect($fresh->remaining_qty_base)->toBe('0.000000');
    expect($fresh->status)->toBe('EMPTY');

    // CT-03: the hash chain this concurrent run produced must still verify as intact —
    // real evidence the row lock serialized writers instead of interleaving them badly
    // enough to corrupt prev_row_hash linkage.
    $result = app(LedgerHasher::class)->verifyChain($item->id);
    expect($result['ok'])->toBeTrue();

    $item->update(['is_active' => false]);
})->group('concurrency');

test('CT-02: 1,000 concurrent 0.001 g withdrawals from a 1 g bottle leave remaining exactly 0.000000', function () {
    [$item, $container, $user] = concurrencyFixtures('g', '1.000000');

    $processes = [];
    for ($i = 0; $i < 20; $i++) {
        $processes[] = spawnIssueOnce($container->id, '0.001000', 'g', $user->id, repeat: 50);
    }

    $tally = waitAndTally($processes);

    expect($tally['ok'] + $tally['insufficient'])->toBe(1000);
    expect($tally['ok'])->toBe(1000);
    expect($tally['insufficient'])->toBe(0);

    $fresh = $container->fresh();
    expect($fresh->remaining_qty_base)->toBe('0.000000');

    $item->update(['is_active' => false]);
})->group('concurrency');
