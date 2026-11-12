<?php

use App\Domain\Loan\Services\LoanDailyNotifier;
use App\Models\Item;
use App\Models\Lab;
use App\Models\LoanAsset;
use App\Models\LoanNoticeLog;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * ITM-2610-051 step 5 (WP-5.6), NFR-LN-05 — `loans:notify-daily` started several times AT THE SAME MOMENT sends each
 * notice once. Real separate OS processes (tests/Concurrency/bin/loan_notify_once.php, each with its own connection,
 * released together), the same method as LoanConcurrencyTest. Run on cmis_testing only (the script refuses anything
 * else) and with the mail driver forced to `array` inside the script.
 *
 * NO RefreshDatabase (the fixtures must be committed to be seen by the other processes): this test LEAVES PERMANENT
 * ROWS in cmis_testing like the other files of this directory — loan_movements (append-only) and what it references.
 * It deactivates its users, items and branch afterwards and deletes the notices and notice-log rows it caused (those two
 * tables are ordinary ones), so nothing later in the same run counts them. The processes run over the WHOLE database
 * (whatever earlier runs left behind is inactive, so it is told nothing); the checks below are therefore per person,
 * plus "what all processes together report as sent = the rows that really appeared".
 */
function spawnLoanNotify(float $startAt): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Concurrency/bin/loan_notify_once.php'), sprintf('%.6F', $startAt)],
        base_path(),
        ['DB_DATABASE' => 'cmis_testing', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'],
    );
    $process->setTimeout(180);
    $process->start();

    return $process;
}

/** Tidies up what the test committed (see the header). */
function retireNoticeScene(Lab $lab, array $userIds): void
{
    Notification::whereIn('user_id', $userIds)->delete();
    LoanNoticeLog::whereIn('user_id', $userIds)->delete();
    User::where('lab_id', $lab->id)->orWhereIn('id', $userIds)->update(['is_active' => false]);
    Item::whereIn('id', LoanAsset::where('lab_id', $lab->id)->pluck('item_id'))->update(['is_active' => false]);
    $lab->update(['is_active' => false]);
}

test('CT-L12 (NFR-LN-05): six simultaneous runs of the daily notices send every notice exactly once — borrower and branch alike', function () {
    [$lab, $asset] = loanBranch(10);
    $people = [];
    try {
        $borrower = staffUser(['lab_id' => $lab->id]);
        $scientist = scientistUser(['lab_id' => $lab->id]);
        $manager = auditorUser(['lab_id' => $lab->id]);
        $people = [$borrower->id, $scientist->id, $manager->id];
        $today = CarbonImmutable::today();

        // Overdue by one day (N6), due in two days (N5), approved and not picked up for 4 days (N4), an incident (N7).
        $late = handedOutLoan($borrower, $asset, 1);
        DB::table('loans')->where('id', $late->id)->update(['loan_date' => $today->subDays(8)->toDateString(), 'due_date' => $today->subDay()->toDateString()]);
        $soon = handedOutLoan($borrower, $asset, 1);
        DB::table('loans')->where('id', $soon->id)->update(['due_date' => $today->addDays(2)->toDateString()]);
        $waiting = approvedLoan($borrower, $asset, 1);
        DB::table('loans')->where('id', $waiting->id)->update([
            'loan_date' => $today->subDays(4)->toDateString(), 'due_date' => $today->addDays(3)->toDateString(), 'approved_at' => $today->subDays(4)->setTime(9, 0),
        ]);
        $broken = handedOutLoan($borrower, $asset, 2);
        $clerk = scientistUser(['lab_id' => $lab->id]);
        recordLoanIncident($broken, $clerk, 'DAMAGED', 1);
        $clerk->update(['is_active' => false]);

        $types = [
            LoanDailyNotifier::TYPE_PICKUP, LoanDailyNotifier::TYPE_PICKUP_DIGEST, LoanDailyNotifier::TYPE_DUE_SOON,
            LoanDailyNotifier::TYPE_OVERDUE, LoanDailyNotifier::TYPE_OVERDUE_DIGEST, LoanDailyNotifier::TYPE_INCIDENT_DIGEST,
        ];
        $before = [];
        foreach ($types as $type) {
            $before[$type] = Notification::where('type', $type)->count();
        }

        $startAt = microtime(true) + 6.0;
        $processes = [];
        for ($i = 0; $i < 6; $i++) {
            $processes[] = spawnLoanNotify($startAt);
        }

        $reported = array_fill_keys($types, 0);
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException("loan_notify_once.php failed (exit {$process->getExitCode()}): {$process->getErrorOutput()}");
            }
            $counts = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            foreach ($types as $type) {
                $reported[$type] += (int) ($counts[$type] ?? 0);
            }
        }

        // Each person got each kind exactly once.
        $once = [
            [$borrower, LoanDailyNotifier::TYPE_PICKUP], [$borrower, LoanDailyNotifier::TYPE_DUE_SOON], [$borrower, LoanDailyNotifier::TYPE_OVERDUE],
            [$scientist, LoanDailyNotifier::TYPE_PICKUP_DIGEST], [$scientist, LoanDailyNotifier::TYPE_OVERDUE_DIGEST], [$scientist, LoanDailyNotifier::TYPE_INCIDENT_DIGEST],
            [$manager, LoanDailyNotifier::TYPE_PICKUP_DIGEST], [$manager, LoanDailyNotifier::TYPE_OVERDUE_DIGEST], [$manager, LoanDailyNotifier::TYPE_INCIDENT_DIGEST],
        ];
        foreach ($once as [$user, $type]) {
            expect(Notification::where('user_id', $user->id)->where('type', $type)->count())->toBe(1, "user #{$user->id} {$type}");
            expect(LoanNoticeLog::where('user_id', $user->id)->where('type', $type)->where('notice_date', $today->toDateString())->count())->toBe(1, "claim #{$user->id} {$type}");
        }
        // What the six processes together say they sent is exactly what appeared — no hidden double sends anywhere.
        foreach ($types as $type) {
            expect(Notification::where('type', $type)->count() - $before[$type])->toBe($reported[$type], $type);
        }
        expect($reported[LoanDailyNotifier::TYPE_OVERDUE])->toBeGreaterThanOrEqual(1);
    } finally {
        retireNoticeScene($lab, $people);
    }
});
