<?php

declare(strict_types=1);

/**
 * ITM-2610-051 step 3 (WP-3.6) and step 4 (WP-4.6) support script — NOT a shipped app feature, never registered as an Artisan command
 * (an unauthenticated "hand out a loan" CLI command would be a permanent way round the Policy / Form Request layer).
 * Same idea as issue_once.php: one Pest process is single-threaded, so the row locks of the loan module can only be
 * contended by real separate OS processes, each with its own DB connection. tests/Feature/LoansConcurrency/
 * LoanConcurrencyTest.php spawns several of these at once.
 *
 * Every action goes through the real service, so the service's own "who may do this" checks still apply.
 *
 * Usage: php loan_action_once.php <action> <start_at> <args...>
 *   handout       <loan_id> <actor_id>                     every line in GOOD condition
 *   return        <loan_id> <actor_id> <line_no> <qty>     GOOD condition
 *   approve       <loan_id> <actor_id>                     MANAGER step, APPROVE
 *   renew-request <loan_id> <actor_id> <Y-m-d>
 *   renew-decide  <loan_id> <actor_id> <seq> <APPROVE|REJECT>
 *   return-incident <loan_id> <actor_id> <line_no> <DAMAGED|LOST> <qty>     step 4: pieces come back damaged / are lost
 *   resolve       <loan_id> <actor_id> <incident_id> <RESOLUTION> [DISPOSITION|-] [receiver_id]
 *                                                          step 4: the result of an incident (fixed reference + note)
 *
 * <start_at> is a Unix time with decimals: the process boots, opens its connection, loads its rows and then waits
 * until that moment, so that all the processes of one test press the button together.
 *
 * Prints exactly one line: "OK" or "REFUSED <the domain message>" and exits 0 (a refusal is an expected outcome
 * under contention). Anything unexpected (a deadlock, a 500-class error) prints "ERROR: ..." to stderr and exits 2.
 * Exits 3 without touching anything when the database is not `cmis_testing`.
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

$action = (string) ($argv[1] ?? '');
$startAt = (float) ($argv[2] ?? 0);
$args = array_slice($argv, 3);

try {
    $loan = App\Models\Loan::findOrFail((int) ($args[0] ?? 0));
    $actor = App\Models\User::with('roles.permissions')->findOrFail((int) ($args[1] ?? 0));

    $run = match ($action) {
        'handout' => function () use ($loan, $actor) {
            $conditions = [];
            foreach ($loan->lines()->get() as $line) {
                $conditions[$line->line_no] = ['condition' => 'GOOD'];
            }
            app(App\Domain\Loan\Services\LoanCirculationService::class)->handOut($actor, $loan, $conditions);
        },
        'return' => fn () => app(App\Domain\Loan\Services\LoanCirculationService::class)
            ->receiveReturn($actor, $loan, [(int) $args[2] => ['qty' => (string) $args[3], 'condition' => 'GOOD']]),
        'approve' => fn () => app(App\Domain\Loan\Services\LoanApprovalService::class)->managerDecide($loan, $actor, 'APPROVE'),
        'renew-request' => fn () => app(App\Domain\Loan\Services\LoanRenewalService::class)->request($actor, $loan, (string) $args[2], null),
        'renew-decide' => fn () => app(App\Domain\Loan\Services\LoanRenewalService::class)
            ->decide($actor, $loan, (int) $args[2], (string) $args[3], $args[3] === 'REJECT' ? 'concurrency test' : null),
        'return-incident' => fn () => app(App\Domain\Loan\Services\LoanCirculationService::class)->receiveReturn($actor, $loan, [
            (int) $args[2] => [$args[3] === 'LOST' ? 'qty_lost' : 'qty_damaged' => (string) $args[4], 'incident_note' => 'concurrency test incident'],
        ]),
        'resolve' => function () use ($loan, $actor, $args) {
            $incident = App\Models\LoanIncident::where('loan_id', $loan->id)->findOrFail((int) $args[2]);
            $disposition = $args[4] ?? '-';
            app(App\Domain\Loan\Services\LoanIncidentService::class)->resolve($actor, $incident, [
                'resolution' => (string) $args[3],
                'disposition' => $disposition === '-' ? null : $disposition,
                'condition_after' => 'GOOD',
                'external_ref' => 'CT-REF-0001',
                'resolution_note' => 'concurrency test result',
                'receiver_id' => $args[5] ?? null,
            ]);
        },
        default => throw new InvalidArgumentException("unknown action '{$action}'"),
    };

    // Everything is loaded and the connection is open: wait for the common start.
    if ($startAt > microtime(true)) {
        time_sleep_until($startAt);
    }

    try {
        $run();
        fwrite(STDOUT, "OK\n");
    } catch (App\Domain\Loan\Exceptions\LoanDomainException $e) {
        fwrite(STDOUT, 'REFUSED '.$e->getMessage()."\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e::class.': '.$e->getMessage()."\n");
    exit(2);
}
