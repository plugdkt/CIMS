<?php

use App\Domain\Loan\Services\LoanDailyNotifier;
use App\Mail\NotificationMail;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanNoticeLog;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 5 (WP-5.6), spec section 10 N4-N7 + section 14, NFR-LN-05: the scheduled notices of the lending
 * module, through the real commands `loans:notify-daily` / `loans:notify-monthly` (on cmis_testing only).
 *
 * The rules as the code has them (LoanDailyNotifier):
 *  - N4 not picked up: APPROVED and the pick-up date (or the approval, if later) is more than `pickup_reminder_days` (3)
 *    behind -> day 1 past that, then every 7th day (1, 8, 15 ...). Borrower + one summary per branch to the counter
 *    (scientists and warehouse managers). In the system ONLY, for everybody.
 *  - N5 due soon: still out, due today or in `due_reminder_days` (2) days -> the borrower, in the system + email.
 *  - N6 overdue: day 1 past the due date, then every 7th day -> the borrower (system + email); on such a day the branch
 *    gets one summary of ALL its overdue documents: system to the counter, email to the warehouse managers only.
 *  - N7 incidents waiting for a result: every day, in the system only, to the people of the branch who hold
 *    `loan.asset_approve`, never about a loan they borrowed / typed in.
 *  - Monthly: every active ADMIN (all branches) and every AUDITOR with a branch (own branch) who holds `loan.report`,
 *    system + email. LAB_MANAGER is not on that list (the brief says ADMIN + AUDITOR).
 *  - NFR-LN-05: a notice is claimed in `loan_notice_log` (UNIQUE) before it is sent; a second run the same day sends
 *    nothing. The two-process race is in tests/Feature/LoansConcurrency/LoanNoticeConcurrencyTest.php.
 *  - Text: document numbers, dates and counts only — no name, no reason, no item, no value.
 */

beforeEach(function () {
    Mail::fake();
});

/**
 * A branch with a counter and the people who must NOT get the branch summaries.
 *
 * @return array<string, User>
 */
function noticeRoster(Lab $lab): array
{
    $other = makeLab();

    return [
        'scientist' => scientistUser(['lab_id' => $lab->id]),
        'auditor' => auditorUser(['lab_id' => $lab->id]),
        'lab_manager' => labManagerUser(['lab_id' => $lab->id]),
        'inactive_scientist' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
        'other_scientist' => scientistUser(['lab_id' => $other->id]),
        'other_auditor' => auditorUser(['lab_id' => $other->id]),
        'no_branch_scientist' => scientistUser(['lab_id' => null]),
        'no_branch_auditor' => auditorUser(['lab_id' => null]),
        'admin_of_branch' => adminUser(['lab_id' => $lab->id]),
        'admin_no_branch' => adminUser(['lab_id' => null]),
        'staff_of_branch' => staffUser(['lab_id' => $lab->id]),
    ];
}

/** Runs the daily command at 07:00 of that day (the scheduler's time) and returns to the caller. */
function runDailyAt($test, CarbonImmutable|\Illuminate\Support\Carbon $day): void
{
    $test->travelTo(CarbonImmutable::parse($day->toDateString())->setTime(7, 0));
    $test->artisan('loans:notify-daily')->assertExitCode(0);
}

/** @return \Illuminate\Support\Collection<int, Notification> */
function noticesOf(User $user, string $type)
{
    return Notification::where('user_id', $user->id)->where('type', $type)->orderBy('id')->get();
}

/**
 * Every NotificationMail since the last Mail::fake(), sent or queued (NotificationMail implements ShouldQueue, so
 * Mail::sent() alone would always be empty and every "no email" check would pass for nothing).
 *
 * @return \Illuminate\Support\Collection<int, NotificationMail>
 */
function noticeMails()
{
    return Mail::sent(NotificationMail::class)->merge(Mail::queued(NotificationMail::class))->values();
}

/** Every text the step-5 notices carried (system rows of the given types + every NotificationMail since the last fake). */
function dailyNoticeText(): string
{
    $types = [
        LoanDailyNotifier::TYPE_PICKUP, LoanDailyNotifier::TYPE_PICKUP_DIGEST, LoanDailyNotifier::TYPE_DUE_SOON,
        LoanDailyNotifier::TYPE_OVERDUE, LoanDailyNotifier::TYPE_OVERDUE_DIGEST, LoanDailyNotifier::TYPE_INCIDENT_DIGEST,
        LoanDailyNotifier::TYPE_MONTHLY_AUDIT,
    ];
    $mails = noticeMails();

    return Notification::whereIn('type', $types)->get()->map(fn ($n) => $n->title.' '.$n->body.' '.$n->link_url)->implode(' ')
        .' '.$mails->map(fn (NotificationMail $m) => $m->title.' '.$m->body.' '.$m->linkUrl)->implode(' ');
}

/** A loan whose texts must not leak: a borrower with a known name, a reason, and an asset with a value. */
function privateLoanScene(int $days = 7): array
{
    [$lab, $asset] = loanBranch();
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '98765.43']);
    $borrower = staffUser(['lab_id' => $lab->id, 'full_name' => 'นางสาวลับเฉพาะ ทดสอบเงียบ', 'username' => 'secret.borrower']);

    return [$lab, $asset->fresh(), $borrower];
}

function assertNoPersonalText(User $borrower): void
{
    $text = dailyNoticeText();
    expect($text)->not->toContain($borrower->full_name)
        ->not->toContain('ลับเฉพาะ')
        ->not->toContain($borrower->username)
        ->not->toContain((string) $borrower->email)
        ->not->toContain('วิชาลับห้ามเปิดเผย')
        ->not->toContain('หมายเหตุลับของผู้ยืม')
        ->not->toContain('98765')
        ->not->toContain('อุปกรณ์ให้ยืมทดสอบ');     // the item name (makeCountItem)
}

// --- N5: due soon ----------------------------------------------------------------------------------------------------

test('N5: the borrower is told on D-2 and on the due date, in the system and by email — not on D-3 or D-1, and not again on a second run', function () {
    [$lab, $asset, $borrower] = privateLoanScene();
    $roster = noticeRoster($lab);
    $loan = handedOutLoan($borrower, $asset, 2, null, loanData(['purpose_detail' => 'วิชาลับห้ามเปิดเผย', 'remark' => 'หมายเหตุลับของผู้ยืม']));
    $due = $loan->due_date;
    Mail::fake();

    runDailyAt($this, $due->copy()->subDays(3));
    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_DUE_SOON))->toHaveCount(0);

    runDailyAt($this, $due->copy()->subDays(2));
    $soon = noticesOf($borrower, LoanDailyNotifier::TYPE_DUE_SOON);
    expect($soon)->toHaveCount(1);
    expect($soon[0]->title)->toBe(__('loans.daily.due_soon_title', ['doc_no' => $loan->doc_no]));
    expect($soon[0]->body)->toBe(__('loans.daily.due_soon_body', ['doc_no' => $loan->doc_no, 'date' => $due->format('d/m/Y')]));
    expect($soon[0]->link_url)->toBe(route('loans.show', $loan));
    expect(loanMailCount($borrower))->toBe(1);

    // Same day, again (the scheduler re-run, or somebody typing the command): nothing new.
    $this->artisan('loans:notify-daily')->assertExitCode(0);
    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_DUE_SOON))->toHaveCount(1);
    expect(loanMailCount($borrower))->toBe(1);

    runDailyAt($this, $due->copy()->subDay());
    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_DUE_SOON))->toHaveCount(1);

    runDailyAt($this, $due);
    $soon = noticesOf($borrower, LoanDailyNotifier::TYPE_DUE_SOON);
    expect($soon)->toHaveCount(2);
    expect($soon[1]->title)->toBe(__('loans.daily.due_today_title', ['doc_no' => $loan->doc_no]));
    expect(loanMailCount($borrower))->toBe(2);

    // The branch is not told about "due soon" (a borrower's matter), and nobody else got anything of N5.
    expect(Notification::where('type', LoanDailyNotifier::TYPE_DUE_SOON)->where('user_id', '!=', $borrower->id)->count())->toBe(0);
    foreach ($roster as $name => $user) {
        expect(loanMailCount($user))->toBe(0, $name);
    }
    assertNoPersonalText($borrower);
});

test('N5: a loan whose pieces are all back (or a returned / cancelled loan) is not "due soon"', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    app(\App\Domain\Loan\Services\LoanCirculationService::class)->receiveReturn($clerk->fresh(), $loan->fresh(), [1 => ['qty' => '2', 'condition' => 'GOOD']]);
    expect($loan->fresh()->status)->toBe('CLOSED');

    runDailyAt($this, $loan->due_date->copy()->subDays(2));
    runDailyAt($this, $loan->due_date);

    expect(Notification::where('type', LoanDailyNotifier::TYPE_DUE_SOON)->count())->toBe(0);
});

// --- N6: overdue -----------------------------------------------------------------------------------------------------

test('N6: on D+1 the borrower is told (system + email) and the branch gets ONE summary: system to the scientists and managers, email to the managers only; nobody else', function () {
    [$lab, $asset, $borrower] = privateLoanScene();
    $roster = noticeRoster($lab);
    $loan = handedOutLoan($borrower, $asset, 2, null, loanData(['purpose_detail' => 'วิชาลับห้ามเปิดเผย']));
    Mail::fake();

    runDailyAt($this, $loan->due_date->copy()->addDay());

    $mine = noticesOf($borrower, LoanDailyNotifier::TYPE_OVERDUE);
    expect($mine)->toHaveCount(1);
    expect($mine[0]->title)->toBe(__('loans.daily.overdue_title', ['doc_no' => $loan->doc_no]));
    expect($mine[0]->link_url)->toBe(route('loans.show', $loan));
    expect(loanMailCount($borrower))->toBe(1);

    $digestBody = __('loans.daily.overdue_digest_body', ['date' => now()->format('d/m/Y'), 'docs' => $loan->doc_no, 'count' => 1]);
    foreach (['scientist', 'auditor', 'lab_manager'] as $name) {
        $got = noticesOf($roster[$name], LoanDailyNotifier::TYPE_OVERDUE_DIGEST);
        expect($got)->toHaveCount(1, $name);
        expect($got[0]->title)->toBe(__('loans.daily.overdue_digest_title', ['count' => 1]), $name);
        expect($got[0]->body)->toBe($digestBody, $name);
    }
    // The link: the managers' branch list (they hold loan.view_all), the scientist's counter page.
    expect(noticesOf($roster['auditor'], LoanDailyNotifier::TYPE_OVERDUE_DIGEST)[0]->link_url)->toBe(route('loans.branch', ['overdue' => 1]));
    expect(noticesOf($roster['scientist'], LoanDailyNotifier::TYPE_OVERDUE_DIGEST)[0]->link_url)->toBe(route('loans.desk'));
    // Email: managers yes, scientist no.
    expect(loanMailCount($roster['auditor']))->toBe(1);
    expect(loanMailCount($roster['lab_manager']))->toBe(1);
    expect(loanMailCount($roster['scientist']))->toBe(0);

    // Inactive, another branch, no branch, ADMIN-only (with or without the branch), a plain member: nothing at all.
    foreach (['inactive_scientist', 'other_scientist', 'other_auditor', 'no_branch_scientist', 'no_branch_auditor', 'admin_of_branch', 'admin_no_branch', 'staff_of_branch'] as $name) {
        expect(Notification::where('user_id', $roster[$name]->id)->whereIn('type', [LoanDailyNotifier::TYPE_OVERDUE, LoanDailyNotifier::TYPE_OVERDUE_DIGEST])->count())->toBe(0, $name);
        expect(loanMailCount($roster[$name]))->toBe(0, $name);
    }

    // The log holds exactly the claims: 1 borrower + 3 counter people, nothing personal in it.
    expect(LoanNoticeLog::count())->toBe(4);
    expect(LoanNoticeLog::where('type', LoanDailyNotifier::TYPE_OVERDUE)->pluck('subject')->all())->toBe(['loan:'.$loan->id]);
    expect(LoanNoticeLog::where('type', LoanDailyNotifier::TYPE_OVERDUE_DIGEST)->pluck('subject')->unique()->values()->all())->toBe(['lab:'.$lab->id]);

    assertNoPersonalText($borrower);
});

test('N6: told on D+1, D+8, D+15 — not on D (due today is N5), D+2 or D+7 — and a second run on the same day sends nothing', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $due = $loan->due_date;
    Mail::fake();

    $expected = [0 => 0, 1 => 1, 2 => 1, 7 => 1, 8 => 2, 9 => 2, 15 => 3];
    foreach ($expected as $daysLate => $count) {
        runDailyAt($this, $due->copy()->addDays($daysLate));
        $this->artisan('loans:notify-daily')->assertExitCode(0);      // the repeat of that day

        expect(noticesOf($borrower, LoanDailyNotifier::TYPE_OVERDUE))->toHaveCount($count, "D+{$daysLate}");
        expect(noticesOf($manager, LoanDailyNotifier::TYPE_OVERDUE_DIGEST))->toHaveCount($count, "D+{$daysLate}");
    }
    // The borrower: one "due today" mail on D (N5) + three overdue mails; the manager: three summaries.
    expect(loanMailCount($borrower))->toBe(4)->and(loanMailCount($manager))->toBe(3);
});

test('N6: the branch summary lists every overdue document of the branch (also the ones not on a reminder day) and only of that branch; 11+ are cut to 10 plus a count', function () {
    [$lab, $asset] = loanBranch(30);
    [$otherLab, $otherAsset] = loanBranch();
    $manager = auditorUser(['lab_id' => $lab->id]);
    $otherManager = auditorUser(['lab_id' => $otherLab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $today = CarbonImmutable::today()->addDays(30);

    // 11 loans 1 day late (a reminder day) and 1 loan 3 days late (not a reminder day), all of the branch.
    $docs = [];
    for ($i = 0; $i < 12; $i++) {
        $loan = handedOutLoan($borrower, $asset, 1);
        $due = $i === 11 ? $today->subDays(3) : $today->subDay();
        DB::table('loans')->where('id', $loan->id)->update(['due_date' => $due->toDateString()]);
        $docs[] = $loan->doc_no;
    }
    // Another branch's overdue loan is not in this branch's summary.
    $foreign = handedOutLoan(staffUser(['lab_id' => $otherLab->id]), $otherAsset, 1);
    DB::table('loans')->where('id', $foreign->id)->update(['due_date' => $today->subDays(3)->toDateString()]);
    Mail::fake();

    runDailyAt($this, $today);

    $digest = noticesOf($manager, LoanDailyNotifier::TYPE_OVERDUE_DIGEST);
    expect($digest)->toHaveCount(1);
    expect($digest[0]->title)->toBe(__('loans.daily.overdue_digest_title', ['count' => 12]));
    expect($digest[0]->body)->toContain(__('loans.daily.and_more', ['count' => 2]));
    expect(count(array_filter($docs, fn (string $doc) => str_contains($digest[0]->body, $doc))))->toBe(10);
    expect($digest[0]->body)->not->toContain($foreign->doc_no);

    // The other branch's only overdue loan is 3 days late: no reminder day there, so no summary for it today.
    expect(noticesOf($otherManager, LoanDailyNotifier::TYPE_OVERDUE_DIGEST))->toHaveCount(0);
});

test('N6: an inactive borrower is not told, the branch still is; a loan with nothing left at the borrower (only a pending incident) is not overdue', function () {
    [$lab, $asset] = loanBranch();
    $manager = auditorUser(['lab_id' => $lab->id]);
    $gone = staffUser(['lab_id' => $lab->id]);
    $late = handedOutLoan($gone, $asset, 1);
    $gone->update(['is_active' => false]);

    $other = staffUser(['lab_id' => $lab->id]);
    $settled = handedOutLoan($other, $asset, 1);
    recordLoanIncident($settled, scientistUser(['lab_id' => $lab->id]), 'LOST', 1);
    expect($settled->fresh()->status)->toBe('PARTIALLY_RETURNED');
    Mail::fake();

    runDailyAt($this, $late->due_date->copy()->addDay());

    expect(noticesOf($gone, LoanDailyNotifier::TYPE_OVERDUE))->toHaveCount(0);
    expect(loanMailCount($gone))->toBe(0);
    expect(noticesOf($other, LoanDailyNotifier::TYPE_OVERDUE))->toHaveCount(0);
    $digest = noticesOf($manager, LoanDailyNotifier::TYPE_OVERDUE_DIGEST);
    expect($digest)->toHaveCount(1);
    expect($digest[0]->body)->toContain($late->doc_no)->not->toContain($settled->doc_no);
});

// --- N4: not picked up -----------------------------------------------------------------------------------------------

test('N4: an approved loan not picked up is told on pick-up day +4 (3 days of grace) and every 7th day after, to the borrower and the counter, in the system only', function () {
    [$lab, $asset, $borrower] = privateLoanScene();
    $roster = noticeRoster($lab);
    $loan = approvedLoan($borrower, $asset, 1, null, ['purpose_detail' => 'วิชาลับห้ามเปิดเผย']);
    $pickup = $loan->loan_date;
    Mail::fake();

    $expected = [3 => 0, 4 => 1, 5 => 1, 10 => 1, 11 => 2, 18 => 3];
    foreach ($expected as $day => $count) {
        runDailyAt($this, $pickup->copy()->addDays($day));
        $this->artisan('loans:notify-daily')->assertExitCode(0);

        expect(noticesOf($borrower, LoanDailyNotifier::TYPE_PICKUP))->toHaveCount($count, "pick-up +{$day}");
        foreach (['scientist', 'auditor', 'lab_manager'] as $name) {
            expect(noticesOf($roster[$name], LoanDailyNotifier::TYPE_PICKUP_DIGEST))->toHaveCount($count, "{$name} +{$day}");
        }
    }

    $first = noticesOf($borrower, LoanDailyNotifier::TYPE_PICKUP)[0];
    expect($first->title)->toBe(__('loans.daily.pickup_title', ['doc_no' => $loan->doc_no]));
    expect($first->body)->toBe(__('loans.daily.pickup_body', ['doc_no' => $loan->doc_no, 'date' => $pickup->format('d/m/Y')]));
    expect(noticesOf($roster['auditor'], LoanDailyNotifier::TYPE_PICKUP_DIGEST)[0]->link_url)->toBe(route('loans.desk'));

    // In the system only: no email to anybody, managers included.
    expect(noticeMails())->toHaveCount(0);
    foreach (['inactive_scientist', 'other_scientist', 'other_auditor', 'no_branch_scientist', 'no_branch_auditor', 'admin_of_branch', 'admin_no_branch', 'staff_of_branch'] as $name) {
        expect(Notification::where('user_id', $roster[$name]->id)->where('type', LoanDailyNotifier::TYPE_PICKUP_DIGEST)->count())->toBe(0, $name);
    }
    // Nothing is cancelled automatically.
    expect($loan->fresh()->status)->toBe('APPROVED');
    assertNoPersonalText($borrower);
});

test('N4: the grace counts from the approval when the approval came after the pick-up date; a configured grace is honoured', function () {
    config(['loans.pickup_reminder_days' => 1]);
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = approvedLoan($borrower, $asset, 1);
    // Approved 5 days after the pick-up date it named.
    DB::table('loans')->where('id', $loan->id)->update(['loan_date' => $loan->loan_date->copy()->subDays(5)->toDateString()]);
    $approvedDay = $loan->approved_at->copy()->startOfDay();

    runDailyAt($this, $approvedDay->copy()->addDay());          // 1 day after the approval = still inside the grace of 1
    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_PICKUP))->toHaveCount(0);
    runDailyAt($this, $approvedDay->copy()->addDays(2));        // first day past it
    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_PICKUP))->toHaveCount(1);
});

// --- N7: incidents waiting for a result -------------------------------------------------------------------------------

test('N7: a pending incident is announced every day, in the system only, to the branch people who hold loan.asset_approve — never to the borrower or ADMIN-only accounts; stops once resolved', function () {
    [$lab, $asset] = loanBranch();
    $roster = noticeRoster($lab);
    // The borrower is a scientist of the branch (holds loan.asset_approve) and must still not be told about their own case.
    $borrower = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'phone_encrypted' => '0812345678', 'profile_completed_at' => now()]);
    $loan = handedOutLoan($borrower, $asset, 2);
    // The recorder is a throw-away clerk (deactivated): whether the recorder — who may not resolve their own case — should
    // be told is not pinned here (see the report to the secretary).
    $clerk = scientistUser(['lab_id' => $lab->id]);
    $incident = recordLoanIncident($loan, $clerk, 'DAMAGED', 1);
    $clerk->update(['is_active' => false]);
    Mail::fake();

    $day1 = CarbonImmutable::today()->addDay();
    runDailyAt($this, $day1);
    $this->artisan('loans:notify-daily')->assertExitCode(0);

    foreach (['scientist', 'auditor', 'lab_manager'] as $name) {
        $got = noticesOf($roster[$name], LoanDailyNotifier::TYPE_INCIDENT_DIGEST);
        expect($got)->toHaveCount(1, $name);
        expect($got[0]->body)->toContain($loan->doc_no);
        expect($got[0]->link_url)->toBe(route('loans.incidents.index'));
    }
    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_INCIDENT_DIGEST))->toHaveCount(0);
    foreach (['inactive_scientist', 'other_scientist', 'other_auditor', 'no_branch_scientist', 'no_branch_auditor', 'admin_of_branch', 'admin_no_branch', 'staff_of_branch'] as $name) {
        expect(noticesOf($roster[$name], LoanDailyNotifier::TYPE_INCIDENT_DIGEST))->toHaveCount(0, $name);
    }
    expect(noticeMails())->toHaveCount(0);

    // Every day while it waits.
    runDailyAt($this, $day1->addDay());
    expect(noticesOf($roster['auditor'], LoanDailyNotifier::TYPE_INCIDENT_DIGEST))->toHaveCount(2);

    // Resolved: no more.
    app(\App\Domain\Loan\Services\LoanIncidentService::class)->resolve($roster['auditor']->fresh(), $incident->fresh(), [
        'resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ',
    ]);
    runDailyAt($this, $day1->addDays(2));
    expect(noticesOf($roster['auditor'], LoanDailyNotifier::TYPE_INCIDENT_DIGEST))->toHaveCount(2);
});

// --- one run, every kind; the repeat -------------------------------------------------------------------------------

test('NFR-LN-05: a second run on the same day sends nothing of any kind (counts, rows, mails and the command output all say so)', function () {
    [$lab, $asset] = loanBranch(20);
    $roster = noticeRoster($lab);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $today = CarbonImmutable::today()->addDays(20);

    $late = handedOutLoan($borrower, $asset, 1);
    DB::table('loans')->where('id', $late->id)->update(['due_date' => $today->subDay()->toDateString()]);
    $soon = handedOutLoan($borrower, $asset, 1);
    DB::table('loans')->where('id', $soon->id)->update(['due_date' => $today->addDays(2)->toDateString()]);
    $waiting = approvedLoan($borrower, $asset, 1);
    DB::table('loans')->where('id', $waiting->id)->update([
        'loan_date' => $today->subDays(4)->toDateString(), 'due_date' => $today->addDays(3)->toDateString(), 'approved_at' => $today->subDays(4)->setTime(9, 0),
    ]);
    $broken = handedOutLoan($borrower, $asset, 2);
    DB::table('loans')->where('id', $broken->id)->update(['due_date' => $today->addDays(5)->toDateString()]);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    recordLoanIncident($broken, $clerk, 'DAMAGED', 1);
    $clerk->update(['is_active' => false]);
    Mail::fake();

    $this->travelTo($today->setTime(7, 0));
    $first = app(LoanDailyNotifier::class)->runDaily();
    expect($first)->toBe([
        LoanDailyNotifier::TYPE_PICKUP => 1,
        LoanDailyNotifier::TYPE_PICKUP_DIGEST => 3,
        LoanDailyNotifier::TYPE_DUE_SOON => 1,
        LoanDailyNotifier::TYPE_OVERDUE => 1,
        LoanDailyNotifier::TYPE_OVERDUE_DIGEST => 3,
        LoanDailyNotifier::TYPE_INCIDENT_DIGEST => 3,
    ]);
    $rows = Notification::count();
    $claims = LoanNoticeLog::count();
    $mails = noticeMails()->count();
    expect($claims)->toBe(12);
    // Mail: due soon + overdue to the borrower, overdue summary to the two managers.
    expect($mails)->toBe(4);

    $this->travelTo($today->setTime(18, 30));      // later the same day
    expect(app(LoanDailyNotifier::class)->runDaily())->toBe(array_map(fn () => 0, $first));
    $this->artisan('loans:notify-daily')
        ->expectsOutputToContain(__('loans.daily_cmd.sent', ['type' => LoanDailyNotifier::TYPE_OVERDUE, 'count' => 0]))
        ->assertExitCode(0);

    expect(Notification::count())->toBe($rows);
    expect(LoanNoticeLog::count())->toBe($claims);
    expect(noticeMails()->count())->toBe($mails);
});

test('NFR-LN-05: a claim already in the log (an earlier run that crashed after claiming) means "already sent" — at most once, never twice', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 1);
    $day = $loan->due_date->copy()->addDay();

    DB::table('loan_notice_log')->insert([
        'notice_date' => $day->toDateString(), 'type' => LoanDailyNotifier::TYPE_OVERDUE, 'user_id' => $borrower->id,
        'subject' => 'loan:'.$loan->id, 'created_at' => now(),
    ]);
    Mail::fake();

    runDailyAt($this, $day);

    expect(noticesOf($borrower, LoanDailyNotifier::TYPE_OVERDUE))->toHaveCount(0);
    expect(loanMailCount($borrower))->toBe(0);
});

test('the notice log is a UNIQUE key: the database itself refuses a second identical claim', function () {
    $user = staffUser();
    $row = ['notice_date' => '2026-11-12', 'type' => LoanDailyNotifier::TYPE_OVERDUE, 'user_id' => $user->id, 'subject' => 'loan:1', 'created_at' => now()];
    DB::table('loan_notice_log')->insert($row);

    expect(fn () => DB::table('loan_notice_log')->insert($row))->toThrow(\Illuminate\Database\QueryException::class);
    expect(DB::table('loan_notice_log')->insertOrIgnore($row))->toBe(0);
    // Another day, kind, person or subject is another notice.
    expect(DB::table('loan_notice_log')->insertOrIgnore(array_merge($row, ['notice_date' => '2026-11-13'])))->toBe(1);
    expect(DB::table('loan_notice_log')->insertOrIgnore(array_merge($row, ['subject' => 'loan:2'])))->toBe(1);
});

test('the daily run prunes notice-log rows older than 400 days and keeps the recent ones', function () {
    $user = staffUser();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 07:00'));
    foreach (['2025-10-07', '2025-10-08', '2026-11-11'] as $day) {      // 401, 400 and 1 day(s) back
        DB::table('loan_notice_log')->insert(['notice_date' => $day, 'type' => 'loan.overdue', 'user_id' => $user->id, 'subject' => 'loan:9', 'created_at' => now()]);
    }

    $this->artisan('loans:notify-daily')->assertExitCode(0);

    expect(LoanNoticeLog::orderBy('notice_date')->pluck('notice_date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all())
        ->toBe(['2025-10-08', '2026-11-11']);
});

// --- monthly audit summary ---------------------------------------------------------------------------------------------

/** One ADJUST_IN of `$qty` on a fresh pool of the branch, at the current (travelled) time. */
function monthlyAdjust(Lab $lab, ?string $ref = null, int $qty = 1): void
{
    $actor = auditorUser(['lab_id' => $lab->id]);
    $approver = labManagerUser(['lab_id' => $lab->id]);
    $asset = registerLoanAsset($actor, $lab, 'POOL', 5);
    loanService()->adjust($actor, $asset, 'IN', $qty, 'นับใหม่พบของเพิ่มในตู้', $ref, $approver->id);
    $actor->update(['is_active' => false]);
    $approver->update(['is_active' => false]);
}

test('monthly: every active ADMIN (all branches) and every AUDITOR with a branch (own branch) is told once, system + email, with the month and a count only', function () {
    [$labA] = loanBranch();
    [$labB] = loanBranch();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00'));
    monthlyAdjust($labA, 'อว 7301/1111');
    monthlyAdjust($labA);
    monthlyAdjust($labB, 'อว 7301/2222');
    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00'));
    monthlyAdjust($labA);                                  // another month: not counted
    $this->travelTo(CarbonImmutable::parse('2026-11-01 07:30'));

    $people = [
        'admin_no_branch' => adminUser(['lab_id' => null]),
        'admin_branch_b' => adminUser(['lab_id' => $labB->id]),
        'auditor_a' => auditorUser(['lab_id' => $labA->id]),
        'auditor_b' => auditorUser(['lab_id' => $labB->id]),
        'auditor_no_branch' => auditorUser(['lab_id' => null]),
        'lab_manager_a' => labManagerUser(['lab_id' => $labA->id]),
        'inactive_admin' => adminUser(['is_active' => false]),
        'inactive_auditor' => auditorUser(['lab_id' => $labA->id, 'is_active' => false]),
        'scientist_a' => scientistUser(['lab_id' => $labA->id]),
        'report_only' => userWithPermissions(['loan.report'], ['lab_id' => $labA->id]),
    ];
    Mail::fake();

    $this->artisan('loans:notify-monthly', ['--month' => '2026-10'])->assertExitCode(0);

    $expect = ['admin_no_branch' => 3, 'admin_branch_b' => 3, 'auditor_a' => 2, 'auditor_b' => 1];
    foreach ($expect as $name => $count) {
        $got = noticesOf($people[$name], LoanDailyNotifier::TYPE_MONTHLY_AUDIT);
        expect($got)->toHaveCount(1, $name);
        expect($got[0]->title)->toBe(__('loans.daily.monthly_audit_title', ['month' => '10/2026']), $name);
        expect($got[0]->body)->toBe(__('loans.daily.monthly_audit_body', ['month' => '10/2026', 'count' => $count]), $name);
        expect($got[0]->link_url)->toBe(route('loans.reports.show', ['report' => 'monthly_audit', 'month' => '2026-10']), $name);
        expect(loanMailCount($people[$name]))->toBe(1, $name);
    }
    foreach (['auditor_no_branch', 'lab_manager_a', 'inactive_admin', 'inactive_auditor', 'scientist_a', 'report_only'] as $name) {
        expect(noticesOf($people[$name], LoanDailyNotifier::TYPE_MONTHLY_AUDIT))->toHaveCount(0, $name);
        expect(loanMailCount($people[$name]))->toBe(0, $name);
    }
    expect(dailyNoticeText())->not->toContain('อว 7301')->not->toContain('นับใหม่');

    // Again (same month, any day): nothing.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 07:30'));
    $this->artisan('loans:notify-monthly', ['--month' => '2026-10'])
        ->expectsOutputToContain(__('loans.daily_cmd.sent', ['type' => LoanDailyNotifier::TYPE_MONTHLY_AUDIT.' 2026-10', 'count' => 0]))
        ->assertExitCode(0);
    expect(Notification::where('type', LoanDailyNotifier::TYPE_MONTHLY_AUDIT)->count())->toBe(4);
    expect(noticeMails())->toHaveCount(4);
});

test('monthly: without --month it reports the month that just closed; a malformed month is refused with exit 1 and sends nothing', function () {
    $admin = adminUser(['lab_id' => null]);
    $this->travelTo(CarbonImmutable::parse('2027-01-01 07:30'));

    $this->artisan('loans:notify-monthly')->assertExitCode(0);
    expect(noticesOf($admin, LoanDailyNotifier::TYPE_MONTHLY_AUDIT)[0]->title)->toBe(__('loans.daily.monthly_audit_title', ['month' => '12/2026']));
    expect(LoanNoticeLog::where('type', LoanDailyNotifier::TYPE_MONTHLY_AUDIT)->pluck('subject')->all())->toBe(['month:2026-12']);

    foreach (['2026-13', '2026-1', '26-01', 'abc', '2026-00'] as $bad) {
        $this->artisan('loans:notify-monthly', ['--month' => $bad])->expectsOutputToContain(__('loans.daily_cmd.bad_month'))->assertExitCode(1);
    }
    expect(Notification::where('type', LoanDailyNotifier::TYPE_MONTHLY_AUDIT)->count())->toBe(1);
});

test('the schedule runs the daily notices at 07:00 (no overlap) and the monthly one on the 1st at 07:30', function () {
    \Illuminate\Support\Facades\Artisan::call('schedule:list');   // makes sure routes/console.php has registered its schedule
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
    $daily = $events->first(fn ($e) => str_contains((string) $e->command, 'loans:notify-daily'));
    $monthly = $events->first(fn ($e) => str_contains((string) $e->command, 'loans:notify-monthly'));

    expect($daily)->not->toBeNull()->and($monthly)->not->toBeNull();
    expect($daily->expression)->toBe('0 7 * * *');
    expect($daily->withoutOverlapping)->toBeTrue();
    expect($monthly->expression)->toBe('30 7 1 * *');
});
