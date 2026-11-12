<?php

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanAsset;
use App\Models\LoanDueChange;
use App\Models\LoanIncident;
use App\Models\LoanMovement;
use App\Models\User;
use Symfony\Component\Process\Process;

/**
 * ITM-2610-051 step 3 (WP-3.6) and step 4 (WP-4.6: CT-L08..CT-L11), NFR-LN-02 — real concurrency for the loan
 * module, not simulated.
 *
 * Same method as tests/Feature/Ledger/ConcurrencyTest.php (T-026): a Pest process is single-threaded, so the row
 * locks (the loan row, then the asset rows) are contended by real separate OS processes
 * (tests/Concurrency/bin/loan_action_once.php), each with its own connection, all released at the same moment.
 *
 * THIS FILE DOES NOT USE RefreshDatabase: the fixtures must be committed to be visible to the other processes.
 * Consequence, the same accepted trade-off as T-026: every run LEAVES PERMANENT ROWS IN `cmis_testing` —
 * `loan_movements` is append-only (triggers + grants), and the loans, lines, assets, items, labs and users those rows
 * reference cannot be deleted either. Each test deactivates the users, the lab and the items it made, so nothing later
 * in the same run is notified or listed because of them, and everything left behind is consistent (`loans:verify`
 * still passes on it). The next test process starts from `migrate:fresh` anyway.
 *
 * This directory sorts after tests/Feature/Loans on purpose: the tests there count rows of whole tables
 * (`loans:verify` "2 assets checked", `Loan::count()`), which is only true while this file has not run yet.
 */

/** Starts one process that performs `$action` at `$startAt`. */
function spawnLoanAction(string $action, float $startAt, int|string ...$args): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Concurrency/bin/loan_action_once.php'), $action, sprintf('%.6F', $startAt), ...array_map('strval', $args)],
        base_path(),
        ['DB_DATABASE' => 'cmis_testing'],
    );
    $process->setTimeout(180);
    $process->start();

    return $process;
}

/**
 * Waits for the processes and returns what each one printed. A process that failed for any reason other than a
 * domain refusal (a deadlock, a 500-class error) fails the test with its stderr.
 *
 * @param  list<Process>  $processes
 * @return array{ok: int, refused: list<string>}
 */
function tallyLoanActions(array $processes): array
{
    $ok = 0;
    $refused = [];

    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException("loan_action_once.php failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}");
        }
        $line = trim($process->getOutput());
        if ($line === 'OK') {
            $ok++;
        } elseif (str_starts_with($line, 'REFUSED ')) {
            $refused[] = substr($line, 8);
        } else {
            throw new RuntimeException("loan_action_once.php printed something unexpected: '{$line}'");
        }
    }

    return ['ok' => $ok, 'refused' => $refused];
}

/** The moment all processes of a test act: far enough ahead for every one of them to have booted. */
function loanStartLine(int $processes): float
{
    return microtime(true) + max(5.0, $processes * 0.35);
}

/** Tidies up what a test committed: nobody of the branch stays active, the branch and its items leave the lists. */
function retireLoanScene(Lab $lab): void
{
    User::where('lab_id', $lab->id)->update(['is_active' => false]);
    Item::whereIn('id', LoanAsset::where('lab_id', $lab->id)->pluck('item_id'))->update(['is_active' => false]);
    $lab->update(['is_active' => false]);
}

/** What `loans:verify` (c) computes for one asset: the pieces the open loan documents still have out. */
function outOnDocuments(LoanAsset $asset): string
{
    $sum = '0';
    foreach (Loan::whereIn('status', Loan::OUT)->whereHas('lines', fn ($q) => $q->where('loan_asset_id', $asset->id))->get() as $loan) {
        foreach ($loan->lines()->where('loan_asset_id', $asset->id)->get() as $line) {
            $sum = bcadd($sum, $line->outstanding(), 6);
        }
    }

    return $sum;
}

/**
 * The three things `loans:verify` checks, for ONE asset (the command itself looks at every asset in the database,
 * including whatever earlier runs of this file left behind, so it is not called here): (a) the hash chain,
 * (b) the asset's counters equal its latest movement's "after" values, (c) on-loan equals what the open documents hold.
 */
function assertLoanAssetVerifies(LoanAsset $asset): void
{
    $asset = $asset->fresh();
    assertLoanChainIntact($asset);

    $last = LoanMovement::where('loan_asset_id', $asset->id)->orderByDesc('id')->firstOrFail();
    expect([$last->qty_total_after, $last->qty_on_loan_after, $last->qty_unavailable_after])
        ->toBe([$asset->qty_total, $asset->qty_on_loan, $asset->qty_unavailable]);

    expect(bccomp($asset->qty_on_loan, outOnDocuments($asset), 6))->toBe(0);
    expect(bccomp($asset->qty_on_loan, $asset->qty_total, 6))->toBeLessThanOrEqual(0);

    // Step 4, the second half of (c): "unavailable" equals the quantities of this asset's PENDING incidents.
    $pending = (string) LoanIncident::where('resolution', 'PENDING')->whereHas('line', fn ($q) => $q->where('loan_asset_id', $asset->id))->sum('qty');
    expect(bccomp($asset->qty_unavailable, is_numeric($pending) ? $pending : '0', 6))->toBe(0);
    expect(bccomp(bcadd($asset->qty_on_loan, $asset->qty_unavailable, 6), $asset->qty_total, 6))->toBeLessThanOrEqual(0);
}

test('CT-L01 (NFR-LN-02): 50 simultaneous hand-outs of 1 piece from a pool of 10 succeed exactly 10 times; on-loan never exceeds the total and the chain is intact', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $borrower = staffUser(['lab_id' => $lab->id]);
        $approver = scientistUser(['lab_id' => $lab->id]);
        $handlers = [scientistUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id])];

        // Approval reserves nothing, so 50 requests for 1 piece each can all be APPROVED against a pool of 10.
        $loans = [];
        for ($i = 0; $i < 50; $i++) {
            $loans[] = approvedLoan($borrower, $asset, 1, $approver);
        }
        expect($asset->fresh()->qty_on_loan)->toBe('0.000000');

        $startAt = loanStartLine(50);
        $processes = [];
        foreach ($loans as $i => $loan) {
            $processes[] = spawnLoanAction('handout', $startAt, $loan->id, $handlers[$i % 3]->id);
        }
        $tally = tallyLoanActions($processes);

        expect($tally['ok'])->toBe(10);
        expect($tally['refused'])->toHaveCount(40);
        expect(array_values(array_unique($tally['refused'])))->toBe([(string) __('loans.error.handout_not_available', ['item' => (string) $asset->item->name_th])]);

        $fresh = $asset->fresh();
        expect($fresh->qty_on_loan)->toBe('10.000000')->and($fresh->qty_total)->toBe('10.000000')->and($fresh->available())->toBe('0.000000');
        $ids = array_map(fn (Loan $l) => $l->id, $loans);
        expect(Loan::whereIn('id', $ids)->where('status', 'ON_LOAN')->count())->toBe(10);
        expect(Loan::whereIn('id', $ids)->where('status', 'APPROVED')->count())->toBe(40);
        expect(LoanMovement::where('loan_asset_id', $asset->id)->where('movement_type', 'HANDOUT')->count())->toBe(10);
        // Each successful loan has exactly one movement and one audit row; a refused one has neither.
        expect(LoanMovement::whereIn('loan_id', $ids)->distinct()->count('loan_id'))->toBe(10);
        expect(AuditLog::where('entity_type', Loan::class)->whereIn('entity_id', $ids)->where('action', 'LOAN_HANDOUT')->count())->toBe(10);

        // The "after" values written by the 10 winners run 1..10 without a gap or a repeat.
        expect(LoanMovement::where('loan_asset_id', $asset->id)->where('movement_type', 'HANDOUT')->orderBy('id')->pluck('qty_on_loan_after')->all())
            ->toBe(array_map(fn (int $n) => $n.'.000000', range(1, 10)));
        expect(outOnDocuments($fresh))->toBe('10.000000');
        assertLoanAssetVerifies($fresh);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L02: two counters press "hand out" on the SAME loan at once: one succeeds, nothing goes out twice', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $second = loanExtraAsset($lab, 5);
        $loan = approvedLoan(staffUser(['lab_id' => $lab->id]), [$asset, $second], 2);
        $handlers = [scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id])];

        $startAt = loanStartLine(4);
        $tally = tallyLoanActions(array_map(fn (User $h) => spawnLoanAction('handout', $startAt, $loan->id, $h->id), $handlers));

        expect($tally['ok'])->toBe(1);
        expect(array_values(array_unique($tally['refused'])))->toBe([(string) __('loans.error.invalid_transition')]);
        expect($loan->fresh()->status)->toBe('ON_LOAN');
        expect($asset->fresh()->qty_on_loan)->toBe('2.000000')->and($second->fresh()->qty_on_loan)->toBe('2.000000');
        expect(LoanMovement::where('loan_id', $loan->id)->count())->toBe(2);   // one per line, once
        expect(AuditLog::where('entity_type', Loan::class)->where('entity_id', $loan->id)->where('action', 'LOAN_HANDOUT')->count())->toBe(1);
        assertLoanAssetVerifies($asset->fresh());
        assertLoanAssetVerifies($second->fresh());
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L03 (BR-L15): two loans race for the one UNIT piece: exactly one gets it', function () {
    [$lab] = loanBranch(1);
    try {
        $unit = loanExtraAsset($lab, 1, null, 'UNIT');
        $approver = scientistUser(['lab_id' => $lab->id]);
        $handler = scientistUser(['lab_id' => $lab->id]);
        $loans = [];
        for ($i = 0; $i < 6; $i++) {
            $loans[] = approvedLoan(staffUser(['lab_id' => $lab->id]), $unit, 1, $approver);
        }

        $startAt = loanStartLine(6);
        $tally = tallyLoanActions(array_map(fn (Loan $l) => spawnLoanAction('handout', $startAt, $l->id, $handler->id), $loans));

        expect($tally['ok'])->toBe(1)->and($tally['refused'])->toHaveCount(5);
        expect($unit->fresh()->qty_on_loan)->toBe('1.000000');
        expect(Loan::whereIn('id', array_map(fn (Loan $l) => $l->id, $loans))->where('status', 'ON_LOAN')->count())->toBe(1);
        expect(LoanMovement::where('loan_asset_id', $unit->id)->where('movement_type', 'HANDOUT')->count())->toBe(1);
        expect(outOnDocuments($unit->fresh()))->toBe('1.000000');
        assertLoanAssetVerifies($unit->fresh());
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L04: the same return recorded twice at once is taken once (a double click, or two counters)', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $handlers = [scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id])];
        $borrower = staffUser(['lab_id' => $lab->id]);

        // (a) everything back, three times at once: one closes the loan, the others find it closed.
        $all = handedOutLoan($borrower, $asset, 2, $handlers[0]);
        // (b) 3 out, three people each take 2 back: one succeeds, 1 piece stays out.
        $part = handedOutLoan($borrower, $asset, 3, $handlers[0]);
        expect($asset->fresh()->qty_on_loan)->toBe('5.000000');

        $startAt = loanStartLine(6);
        $closing = array_map(fn (User $h) => spawnLoanAction('return', $startAt, $all->id, $h->id, 1, 2), $handlers);
        $partial = array_map(fn (User $h) => spawnLoanAction('return', $startAt, $part->id, $h->id, 1, 2), $handlers);
        $closingTally = tallyLoanActions($closing);
        $partialTally = tallyLoanActions($partial);

        expect($closingTally['ok'])->toBe(1);
        expect(array_values(array_unique($closingTally['refused'])))->toBe([(string) __('loans.error.invalid_transition')]);
        expect($all->fresh()->status)->toBe('CLOSED');
        expect($all->lines()->firstOrFail()->qty_returned_ok)->toBe('2.000000');
        expect(LoanMovement::where('loan_id', $all->id)->where('movement_type', 'RETURN_OK')->count())->toBe(1);

        expect($partialTally['ok'])->toBe(1);
        expect(array_values(array_unique($partialTally['refused'])))->toBe([(string) __('loans.error.return_too_much')]);
        expect($part->fresh()->status)->toBe('PARTIALLY_RETURNED');
        expect($part->lines()->firstOrFail()->qty_returned_ok)->toBe('2.000000');
        expect(LoanMovement::where('loan_id', $part->id)->where('movement_type', 'RETURN_OK')->count())->toBe(1);

        // 5 went out, 4 came back, never more.
        expect($asset->fresh()->qty_on_loan)->toBe('1.000000');
        expect(outOnDocuments($asset->fresh()))->toBe('1.000000');
        expect(AuditLog::where('entity_type', Loan::class)->whereIn('entity_id', [$all->id, $part->id])->where('action', 'LOAN_RETURN')->count())->toBe(2);
        assertLoanAssetVerifies($asset->fresh());
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L05: two approvers decide the same waiting renewal at once: one decision stands, the due date moves at most once', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $borrower = staffUser(['lab_id' => $lab->id]);
        $approvers = [scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id])];
        $renewals = app(\App\Domain\Loan\Services\LoanRenewalService::class);

        $make = function () use ($borrower, $asset, $approvers, $renewals): array {
            $loan = handedOutLoan($borrower, $asset, 1, $approvers[0]);
            $renewals->request($borrower->fresh(), $loan, $loan->due_date->copy()->addDays(2)->format('Y-m-d'), null);              // automatic
            $wanted = $loan->fresh()->due_date->copy()->addDays(3)->format('Y-m-d');
            $renewals->request($borrower->fresh(), $loan->fresh(), $wanted, null);                                                   // PENDING, seq 2

            return [$loan->fresh(), $wanted];
        };
        [$bothApprove, $wantedA] = $make();
        [$split, $wantedB] = $make();
        $dueB = $split->due_date->toDateString();

        $startAt = loanStartLine(8);
        $approveAll = array_map(fn (User $a) => spawnLoanAction('renew-decide', $startAt, $bothApprove->id, $a->id, 2, 'APPROVE'), $approvers);
        $mixed = [];
        foreach ($approvers as $i => $approver) {
            $mixed[] = spawnLoanAction('renew-decide', $startAt, $split->id, $approver->id, 2, $i % 2 === 0 ? 'APPROVE' : 'REJECT');
        }
        $tallyA = tallyLoanActions($approveAll);
        $tallyB = tallyLoanActions($mixed);

        // (a) four approvals at once: one is recorded.
        expect($tallyA['ok'])->toBe(1);
        expect(array_values(array_unique($tallyA['refused'])))->toBe([(string) __('loans.error.invalid_transition')]);
        expect($bothApprove->fresh()->due_date->toDateString())->toBe($wantedA)->and($bothApprove->fresh()->renew_count)->toBe(2);
        expect(loanRenewAudit($bothApprove)->where('action', 'LOAN_RENEW_APPROVE'))->toHaveCount(1);

        // (b) approvals and rejections at once: exactly one decision, and the loan agrees with whichever it was.
        expect($tallyB['ok'])->toBe(1)->and($tallyB['refused'])->toHaveCount(3);
        $row = LoanDueChange::where('loan_id', $split->id)->where('seq', 2)->firstOrFail();
        expect($row->status)->toBeIn(['APPROVED', 'REJECTED'])->and($row->approved_by)->not->toBeNull();
        $fresh = $split->fresh();
        if ($row->status === 'APPROVED') {
            expect($fresh->due_date->toDateString())->toBe($wantedB)->and($fresh->renew_count)->toBe(2);
        } else {
            expect($fresh->due_date->toDateString())->toBe($dueB)->and($fresh->renew_count)->toBe(1);
        }
        expect(loanRenewAudit($split)->whereIn('action', ['LOAN_RENEW_APPROVE', 'LOAN_RENEW_REJECT']))->toHaveCount(1);
        expect(LoanDueChange::where('loan_id', $split->id)->count())->toBe(2);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L06: a double-clicked renewal request makes one change, not two', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $borrower = staffUser(['lab_id' => $lab->id]);
        $loan = handedOutLoan($borrower, $asset, 1);
        $wanted = $loan->due_date->copy()->addDays(4)->format('Y-m-d');

        $startAt = loanStartLine(4);
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $processes[] = spawnLoanAction('renew-request', $startAt, $loan->id, $borrower->id, $wanted);
        }
        $tally = tallyLoanActions($processes);

        expect($tally['ok'])->toBe(1);
        // The later ones see the date already moved: "the new date must be later than the current one".
        expect(array_values(array_unique($tally['refused'])))->toBe([(string) __('loans.error.renew_not_later')]);
        expect(LoanDueChange::where('loan_id', $loan->id)->count())->toBe(1);
        expect($loan->fresh()->due_date->toDateString())->toBe($wanted)->and($loan->fresh()->renew_count)->toBe(1);
        expect(loanRenewAudit($loan))->toHaveCount(1);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L07: two approvers of the branch approve the same request at once: one approval row, one decision', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);
        $approvers = [scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id])];

        $startAt = loanStartLine(4);
        $tally = tallyLoanActions(array_map(fn (User $a) => spawnLoanAction('approve', $startAt, $loan->id, $a->id), $approvers));

        expect($tally['ok'])->toBe(1);
        expect(array_values(array_unique($tally['refused'])))->toBe([(string) __('loans.error.invalid_transition')]);
        $fresh = $loan->fresh();
        expect($fresh->status)->toBe('APPROVED')->and($fresh->approved_by)->toBeIn(array_map(fn (User $a) => $a->id, $approvers));
        expect(LoanApproval::where('loan_id', $loan->id)->count())->toBe(1);
        expect(LoanApproval::where('loan_id', $loan->id)->firstOrFail()->actor_id)->toBe($fresh->approved_by);
        expect(AuditLog::where('entity_type', Loan::class)->where('entity_id', $loan->id)->where('action', 'LOAN_APPROVE')->count())->toBe(1);
        expect($asset->fresh()->qty_on_loan)->toBe('0.000000');   // approving reserves nothing
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

// --- step 4 (WP-4.6): incidents and their results -------------------------------------------------------------------

test('CT-L08: several people record the result of the SAME incident at once (repair vs write-off): exactly one result stands, one movement, the loan closes once', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $recorder = scientistUser(['lab_id' => $lab->id]);
        $resolvers = [auditorUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id])];
        $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), $asset, 2, $recorder);
        $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 2);
        attachLoanIncidentFile($incident, $recorder);   // so that the write-off is not refused for a missing letter
        expect($asset->fresh()->qty_unavailable)->toBe('2.000000')->and($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');

        $startAt = loanStartLine(4);
        $processes = [];
        foreach ($resolvers as $i => $resolver) {
            $processes[] = $i % 2 === 0
                ? spawnLoanAction('resolve', $startAt, $loan->id, $resolver->id, $incident->id, 'REPAIRED')
                : spawnLoanAction('resolve', $startAt, $loan->id, $resolver->id, $incident->id, 'WAIVED', 'WRITE_OFF');
        }
        $tally = tallyLoanActions($processes);

        expect($tally['ok'])->toBe(1);
        expect(array_values(array_unique($tally['refused'])))->toBe([(string) __('loans.error.incident_not_pending')]);

        $done = $incident->fresh();
        expect($done->resolution)->toBeIn(['REPAIRED', 'WAIVED'])->and($done->resolved_by)->toBeIn(array_map(fn (User $u) => $u->id, $resolvers));
        $results = LoanMovement::where('incident_id', $incident->id)->whereIn('movement_type', ['REPAIR_DONE', 'WRITE_OFF', 'REPLACE_IN'])->get();
        expect($results)->toHaveCount(1);
        $fresh = $asset->fresh();
        if ($done->resolution === 'REPAIRED') {
            expect([$done->disposition, $results[0]->movement_type, $fresh->qty_total, $fresh->qty_unavailable])->toBe(['RETURN_TO_SERVICE', 'REPAIR_DONE', '10.000000', '0.000000']);
        } else {
            expect([$done->disposition, $results[0]->movement_type, $fresh->qty_total, $fresh->qty_unavailable])->toBe(['WRITE_OFF', 'WRITE_OFF', '8.000000', '0.000000']);
        }
        expect($results[0]->created_by)->toBe($done->resolved_by);
        // BR-L12: it was the last open thing on the document.
        expect($loan->fresh()->status)->toBe('CLOSED')->and($loan->fresh()->closed_at)->not->toBeNull();
        expect(AuditLog::where('action', 'LOAN_INCIDENT_RESOLVE')->where('entity_type', LoanIncident::class)->where('entity_id', $incident->id)->count())->toBe(1);
        expect(AuditLog::where('action', 'LOAN_WRITE_OFF')->where('entity_type', LoanAsset::class)->where('entity_id', $asset->id)->count())->toBe($done->resolution === 'WAIVED' ? 1 : 0);
        assertLoanAssetVerifies($fresh);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L09: the same damaged / lost return recorded several times at once is taken once: one incident, never more pieces than were out', function () {
    [$lab, $asset] = loanBranch(10);
    try {
        $handlers = [scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id])];
        $borrower = staffUser(['lab_id' => $lab->id]);
        // (a) 2 out, three people each record both as damaged. (b) 3 out: damaged 2 / lost 2 / usable 2 at once.
        $same = handedOutLoan($borrower, $asset, 2, $handlers[0]);
        $mixed = handedOutLoan($borrower, $asset, 3, $handlers[0]);
        expect($asset->fresh()->qty_on_loan)->toBe('5.000000');

        $startAt = loanStartLine(6);
        $sameRuns = array_map(fn (User $h) => spawnLoanAction('return-incident', $startAt, $same->id, $h->id, 1, 'DAMAGED', 2), $handlers);
        $mixedRuns = [
            spawnLoanAction('return-incident', $startAt, $mixed->id, $handlers[0]->id, 1, 'DAMAGED', 2),
            spawnLoanAction('return-incident', $startAt, $mixed->id, $handlers[1]->id, 1, 'LOST', 2),
            spawnLoanAction('return', $startAt, $mixed->id, $handlers[2]->id, 1, 2),
        ];
        $sameTally = tallyLoanActions($sameRuns);
        $mixedTally = tallyLoanActions($mixedRuns);

        // (a) one incident of 2; the loan stays open (BR-L12), so the losers are refused for the quantity, not the status.
        expect($sameTally['ok'])->toBe(1);
        expect(array_values(array_unique($sameTally['refused'])))->toBe([(string) __('loans.error.return_too_much')]);
        $incidents = LoanIncident::where('loan_id', $same->id)->get();
        expect($incidents)->toHaveCount(1);
        expect([$incidents[0]->kind, $incidents[0]->qty, $incidents[0]->resolution])->toBe(['DAMAGED', '2.000000', 'PENDING']);
        expect(LoanMovement::where('loan_id', $same->id)->where('movement_type', 'RETURN_DAMAGED')->count())->toBe(1);
        expect($same->lines()->firstOrFail()->qty_returned_damaged)->toBe('2.000000')->and($same->fresh()->status)->toBe('PARTIALLY_RETURNED');

        // (b) exactly one of the three took its 2 pieces; 1 piece is still out.
        expect($mixedTally['ok'])->toBe(1);
        expect(array_values(array_unique($mixedTally['refused'])))->toBe([(string) __('loans.error.return_too_much')]);
        $line = $mixed->lines()->firstOrFail();
        expect($line->outstanding())->toBe('1.000000');
        expect(bcadd(bcadd($line->qty_returned_ok, $line->qty_returned_damaged, 6), $line->qty_lost, 6))->toBe('2.000000');
        expect(LoanMovement::where('loan_id', $mixed->id)->whereIn('movement_type', ['RETURN_OK', 'RETURN_DAMAGED', 'MARK_LOST'])->count())->toBe(1);
        expect(LoanIncident::where('loan_id', $mixed->id)->count())->toBeLessThanOrEqual(1);

        // 5 went out; 4 are no longer with the borrower; nothing was counted twice.
        $fresh = $asset->fresh();
        expect($fresh->qty_on_loan)->toBe('1.000000')->and($fresh->qty_total)->toBe('10.000000');
        expect(bccomp($fresh->qty_unavailable, '2', 6))->toBeGreaterThanOrEqual(0);
        assertLoanAssetVerifies($fresh);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L10 (BR-L12): the last ordinary return and the last result of the same loan arrive together: both succeed and the loan is CLOSED, never left open', function () {
    [$lab, $asset] = loanBranch(20);
    try {
        $recorder = scientistUser(['lab_id' => $lab->id]);
        $handler = scientistUser(['lab_id' => $lab->id]);
        $resolver = auditorUser(['lab_id' => $lab->id]);
        $borrower = staffUser(['lab_id' => $lab->id]);

        // Six loans, each with 1 piece still out and 1 damaged piece waiting for its result.
        $pairs = [];
        for ($i = 0; $i < 6; $i++) {
            $loan = handedOutLoan($borrower, $asset, 2, $recorder);
            $pairs[] = [$loan, recordLoanIncident($loan, $recorder, 'DAMAGED', 1)];
        }

        $startAt = loanStartLine(12);
        $processes = [];
        foreach ($pairs as [$loan, $incident]) {
            $processes[] = spawnLoanAction('return', $startAt, $loan->id, $handler->id, 1, 1);
            $processes[] = spawnLoanAction('resolve', $startAt, $loan->id, $resolver->id, $incident->id, 'REPAIRED');
        }
        $tally = tallyLoanActions($processes);

        // Nobody is refused and nothing deadlocks (both take the loan row first, then the asset).
        expect($tally['ok'])->toBe(12)->and($tally['refused'])->toBe([]);
        foreach ($pairs as [$loan, $incident]) {
            $fresh = $loan->fresh();
            expect($fresh->status)->toBe('CLOSED', "loan {$loan->doc_no} must be closed by whichever came second");
            expect($fresh->closed_at)->not->toBeNull();
            expect($incident->fresh()->resolution)->toBe('REPAIRED');
            // Closed exactly once: by the return's audit row or by the result's, not by both and not by neither.
            $byReturn = AuditLog::where('action', 'LOAN_RETURN')->where('entity_type', Loan::class)->where('entity_id', $loan->id)->get()->filter(fn ($a) => $a->new_value['closed'] === true)->count();
            $byResult = AuditLog::where('action', 'LOAN_INCIDENT_RESOLVE')->where('entity_type', LoanIncident::class)->where('entity_id', $incident->id)->get()->filter(fn ($a) => $a->new_value['loan_closed'] === true)->count();
            expect($byReturn + $byResult)->toBe(1);
        }
        $fresh = $asset->fresh();
        expect([$fresh->qty_total, $fresh->qty_on_loan, $fresh->qty_unavailable])->toBe(['20.000000', '0.000000', '0.000000']);
        assertLoanAssetVerifies($fresh);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');

test('CT-L11: results of DIFFERENT incidents on one asset recorded at once (repair, write-off, replacement) with hand-outs racing: all succeed, totals add up, the hash chain is unbroken', function () {
    [$lab, $asset] = loanBranch(20);
    try {
        $recorder = scientistUser(['lab_id' => $lab->id]);
        $receiver = scientistUser(['lab_id' => $lab->id]);
        $resolvers = [auditorUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id])];
        $borrower = staffUser(['lab_id' => $lab->id]);

        // Nine loans of 1 piece, each lost or damaged: three of each result, plus two hand-outs racing on the same asset.
        $plan = [];
        foreach (range(0, 8) as $i) {
            $loan = handedOutLoan($borrower, $asset, 1, $recorder);
            $incident = recordLoanIncident($loan, $recorder, $i % 3 === 0 ? 'DAMAGED' : 'LOST', 1);
            attachLoanIncidentFile($incident, $recorder);
            $plan[] = [$loan, $incident, ['REPAIRED', 'WAIVED', 'REPLACED'][$i % 3]];
        }
        $waiting = [approvedLoan($borrower, $asset, 1), approvedLoan($borrower, $asset, 1)];
        expect($asset->fresh()->qty_unavailable)->toBe('9.000000');

        $startAt = loanStartLine(11);
        $processes = [];
        foreach ($plan as $i => [$loan, $incident, $result]) {
            $resolver = $resolvers[$i % 3];
            $processes[] = match ($result) {
                'REPAIRED' => spawnLoanAction('resolve', $startAt, $loan->id, $resolver->id, $incident->id, 'REPAIRED'),
                'WAIVED' => spawnLoanAction('resolve', $startAt, $loan->id, $resolver->id, $incident->id, 'WAIVED', 'WRITE_OFF'),
                'REPLACED' => spawnLoanAction('resolve', $startAt, $loan->id, $resolver->id, $incident->id, 'REPLACED', '-', $receiver->id),
            };
        }
        foreach ($waiting as $loan) {
            $processes[] = spawnLoanAction('handout', $startAt, $loan->id, $recorder->id);
        }
        $tally = tallyLoanActions($processes);

        expect($tally['ok'])->toBe(11)->and($tally['refused'])->toBe([]);
        foreach ($plan as [$loan, $incident, $result]) {
            expect($incident->fresh()->resolution)->toBe($result);
            expect($loan->fresh()->status)->toBe('CLOSED');
        }
        // 20 - 3 written off (the 3 replaced ones went out and came back in) = 17; 2 are out; nothing is unavailable.
        $fresh = $asset->fresh();
        expect([$fresh->qty_total, $fresh->qty_on_loan, $fresh->qty_unavailable, $fresh->status])->toBe(['17.000000', '2.000000', '0.000000', 'ACTIVE']);
        $types = LoanMovement::where('loan_asset_id', $asset->id)->whereNotNull('incident_id')->pluck('movement_type')->countBy()->all();
        expect($types['REPAIR_DONE'] ?? 0)->toBe(3)->and($types['WRITE_OFF'] ?? 0)->toBe(6)->and($types['REPLACE_IN'] ?? 0)->toBe(3);
        // Every REPLACE_IN carries two different people.
        expect(LoanMovement::where('loan_asset_id', $asset->id)->where('movement_type', 'REPLACE_IN')->get()->every(fn ($m) => $m->created_by === $receiver->id && $m->approved_by !== null && $m->approved_by !== $m->created_by))->toBeTrue();
        assertLoanAssetVerifies($fresh);
    } finally {
        retireLoanScene($lab);
    }
})->group('concurrency');
