<?php

use App\Domain\Loan\Reports\LoanReportService;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 5 (WP-5.6), spec section 14: the lending dashboard, the eight reports and their CSV exports.
 *
 * The rules as the code has them (LoanReportRequest / LoanReportScope / LoanReportService / LoanReportController):
 *  - access = `loan.report` (warehouse managers LAB_MANAGER / AUDITOR, and ADMIN); everyone else 403, a guest -> login;
 *  - a manager reads their OWN branch only; naming another branch (`lab_id`) is 403, not quietly replaced; a manager
 *    with no branch opens the pages and sees nothing; ADMIN reads every branch or the one picked;
 *  - `{report}` outside the fixed list = 404;
 *  - prices / values only for holders of `loan.asset_manage`;
 *  - every export: CSV through CsvInjectionGuard (headings too), audited as LOAN_REPORT_EXPORT BEFORE the file leaves,
 *    the audit row says WHETHER the search boxes were used, never what was typed.
 */

beforeEach(function () {
    Mail::fake();
});

/** GET an export and return its rows (heading row first) parsed back from CSV, after checking the response itself. */
function loanReportCsv($test, User $user, string $report, array $query = []): array
{
    $response = $test->actingAs($user)->get(route('loans.reports.export', array_merge(['report' => $report], $query)));
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="loan-report-'.$report.'-');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $body = $response->getContent();
    expect(substr($body, 0, 3))->toBe("\xEF\xBB\xBF");

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, substr($body, 3));
    rewind($handle);
    $rows = [];
    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $cells;
    }
    fclose($handle);

    return $rows;
}

/**
 * Two branches with known contents (today = the real today):
 *  A: loan a1 (2 pieces out, 3 days overdue), loan a2 (2 out, 1 back damaged = 1 pending incident), one SUBMITTED loan;
 *  B: loan b1 (1 piece out, 1 day overdue).
 * The pool of A has a replacement value of 12345.67.
 *
 * @return array<string, mixed>
 */
function loanReportScene(): array
{
    [$labA, $assetA] = loanBranch(10);
    [$labB, $assetB] = loanBranch(10);
    DB::table('loan_assets')->where('id', $assetA->id)->update(['replacement_value' => '12345.67']);
    $today = CarbonImmutable::today();

    $borrowerA = staffUser(['lab_id' => $labA->id, 'full_name' => 'ผู้ยืมสาขาเอ ทดสอบรายงาน']);
    $a1 = handedOutLoan($borrowerA, $assetA, 2);
    DB::table('loans')->where('id', $a1->id)->update(['loan_date' => $today->subDays(10)->toDateString(), 'due_date' => $today->subDays(3)->toDateString()]);
    $a2 = handedOutLoan($borrowerA, $assetA, 2);
    $clerk = scientistUser(['lab_id' => $labA->id]);
    $incident = recordLoanIncident($a2, $clerk, 'DAMAGED', 1);
    $clerk->update(['is_active' => false]);
    $pending = submittedLoan(staffUser(['lab_id' => $labA->id]), $assetA, 1);

    $borrowerB = staffUser(['lab_id' => $labB->id, 'full_name' => 'ผู้ยืมสาขาบี ทดสอบรายงาน']);
    $b1 = handedOutLoan($borrowerB, $assetB, 1);
    DB::table('loans')->where('id', $b1->id)->update(['loan_date' => $today->subDays(8)->toDateString(), 'due_date' => $today->subDay()->toDateString()]);

    return [
        'labA' => $labA, 'labB' => $labB, 'assetA' => $assetA->fresh(), 'assetB' => $assetB->fresh(),
        'borrowerA' => $borrowerA, 'borrowerB' => $borrowerB,
        'a1' => $a1->fresh(), 'a2' => $a2->fresh(), 'pending' => $pending, 'b1' => $b1->fresh(), 'incident' => $incident,
        'managerA' => auditorUser(['lab_id' => $labA->id]),
        'labManagerA' => labManagerUser(['lab_id' => $labA->id]),
        'managerB' => auditorUser(['lab_id' => $labB->id]),
        'admin' => adminUser(['lab_id' => null]),
    ];
}

// --- who may open what -------------------------------------------------------------------------------------------------

dataset('loan report roles', [
    'student' => ['student', 403],
    'staff' => ['staff', 403],
    'advisor' => ['advisor', 403],
    'scientist' => ['scientist', 403],
    'nocard' => ['nocard', 403],
    'lab_manager' => ['lab_manager', 200],
    'auditor' => ['auditor', 200],
    'admin' => ['admin', 200],
    'admin + scientist' => ['admin+scientist', 200],
]);

test('the dashboard, a report page and its export answer each role as designed, asked directly by URL; a refused export writes no audit row', function (string $kind, int $status) {
    $user = navUser($kind);

    $this->actingAs($user)->get(route('loans.reports.index'))->assertStatus($status);
    foreach (LoanReportService::keys() as $report) {
        $this->actingAs($user)->get(route('loans.reports.show', $report))->assertStatus($status);
        $this->actingAs($user)->get(route('loans.reports.export', $report))->assertStatus($status);
    }
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->count())->toBe($status === 200 ? count(LoanReportService::keys()) : 0);
    expect($user->can('browseReports', Loan::class))->toBe($status === 200);
})->with('loan report roles');

test('a guest is sent to the login page', function () {
    $this->get(route('loans.reports.index'))->assertRedirect(route('login'));
    $this->get(route('loans.reports.show', 'outstanding'))->assertRedirect(route('login'));
    $this->get(route('loans.reports.export', 'outstanding'))->assertRedirect(route('login'));
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->count())->toBe(0);
});

test('a report name outside the fixed list is 404, for the page and for the export, whoever asks', function () {
    $manager = navUser('auditor');
    $admin = navUser('admin');

    foreach (['unknown', 'OUTSTANDING', 'outstanding ', 'self_approval', 'outstanding.csv', '1'] as $bad) {
        foreach ([$manager, $admin] as $user) {
            $this->actingAs($user)->get('/loans/reports/'.rawurlencode($bad))->assertNotFound();
            $this->actingAs($user)->get('/loans/reports/'.rawurlencode($bad).'/export')->assertNotFound();
        }
    }
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->count())->toBe(0);
    expect(LoanReportService::keys())->toBe(['outstanding', 'availability', 'movements', 'borrower_summary', 'incidents', 'monthly_audit', 'self_actions', 'kpi']);
});

// --- branch scoping -------------------------------------------------------------------------------------------------------

test('a manager naming ANOTHER branch (or garbage) gets 403 on every page and export and no file and no audit row; naming their own branch is fine', function () {
    $s = loanReportScene();
    $managerA = $s['managerA'];

    foreach ([(string) $s['labB']->id, 'abc', '-1', $s['labA']->id.'x', '0'] as $asked) {
        $this->actingAs($managerA)->get(route('loans.reports.index', ['lab_id' => $asked]))->assertForbidden();
        foreach (LoanReportService::keys() as $report) {
            $this->actingAs($managerA)->get(route('loans.reports.show', ['report' => $report, 'lab_id' => $asked]))->assertForbidden();
            $this->actingAs($managerA)->get(route('loans.reports.export', ['report' => $report, 'lab_id' => $asked]))->assertForbidden();
        }
    }
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->count())->toBe(0);

    // Their own branch, named or not, gives the same rows.
    expect(loanReportCsv($this, $managerA, 'outstanding', ['lab_id' => $s['labA']->id]))->toBe(loanReportCsv($this, $managerA, 'outstanding'));
});

test('a manager with no branch opens every page and export (200) but reads nothing; naming any branch is 403', function () {
    $s = loanReportScene();
    $lost = auditorUser(['lab_id' => null]);

    $page = $this->actingAs($lost)->get(route('loans.reports.index'))->assertOk();
    $page->assertSee(__('loans.report.no_lab'));
    $page->assertViewHas('summary', fn (array $sum) => [$sum['pending'], $sum['out'], $sum['overdue'], $sum['incidents'], $sum['top']] === [0, 0, 0, 0, []]
        && collect($sum['monthly'])->sum('count') === 0);

    foreach (LoanReportService::keys() as $report) {
        $this->actingAs($lost)->get(route('loans.reports.show', $report))->assertOk()->assertSee(__('loans.report.no_lab'));
        $rows = loanReportCsv($this, $lost, $report);
        if ($report === 'kpi') {
            // The KPI table always has its metric rows; every count is zero / empty.
            expect(collect(array_slice($rows, 1))->pluck(1)->filter(fn ($v) => $v !== '' && $v !== '0')->all())->toBe([], $report);
        } else {
            expect(array_slice($rows, 1))->toBe([], $report);
        }
        $this->actingAs($lost)->get(route('loans.reports.show', ['report' => $report, 'lab_id' => $s['labA']->id]))->assertForbidden();
    }
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->latest('id')->first()->new_value['lab'])->toBe('0');
});

test('the outstanding report counts exactly the lines still out: own branch for a manager, every branch for ADMIN, one branch when ADMIN picks it', function () {
    $s = loanReportScene();

    $docs = fn (array $rows) => collect(array_slice($rows, 1))->pluck(0)->sort()->values()->all();
    expect($docs(loanReportCsv($this, $s['managerA'], 'outstanding')))->toBe(collect([$s['a1']->doc_no, $s['a2']->doc_no])->sort()->values()->all());
    expect($docs(loanReportCsv($this, $s['labManagerA'], 'outstanding')))->toBe($docs(loanReportCsv($this, $s['managerA'], 'outstanding')));
    expect($docs(loanReportCsv($this, $s['managerB'], 'outstanding')))->toBe([$s['b1']->doc_no]);
    expect(loanReportCsv($this, $s['admin'], 'outstanding'))->toHaveCount(1 + 3);
    expect($docs(loanReportCsv($this, $s['admin'], 'outstanding', ['lab_id' => $s['labB']->id])))->toBe([$s['b1']->doc_no]);

    // The cells of a1: borrower, quantity still out, due date, days late.
    $a1 = collect(loanReportCsv($this, $s['managerA'], 'outstanding'))->firstWhere(0, $s['a1']->doc_no);
    expect([$a1[1], $a1[2], $a1[6], $a1[8], $a1[9]])->toBe([
        $s['labA']->code, 'ผู้ยืมสาขาเอ ทดสอบรายงาน', '2', CarbonImmutable::today()->subDays(3)->format('d/m/Y'), '3',
    ]);
    // a2: 2 out, 1 came back damaged -> 1 still with the borrower, not late.
    $a2 = collect(loanReportCsv($this, $s['managerA'], 'outstanding'))->firstWhere(0, $s['a2']->doc_no);
    expect([$a2[6], $a2[9]])->toBe(['1', '0']);

    // Filters: overdue by at least N days, and the borrower box.
    expect($docs(loanReportCsv($this, $s['managerA'], 'outstanding', ['min_days' => 3])))->toBe([$s['a1']->doc_no]);
    expect($docs(loanReportCsv($this, $s['managerA'], 'outstanding', ['min_days' => 4])))->toBe([]);
    expect(loanReportCsv($this, $s['managerA'], 'outstanding', ['borrower' => 'สาขาเอ']))->toHaveCount(3);
    expect(loanReportCsv($this, $s['managerA'], 'outstanding', ['borrower' => 'สาขาบี']))->toHaveCount(1);   // B's borrower is not in A's report
});

test('the dashboard counts match the data and follow the same branch rule', function () {
    $s = loanReportScene();

    $this->actingAs($s['managerA'])->get(route('loans.reports.index'))->assertOk()
        ->assertViewHas('summary', fn (array $sum) => [$sum['pending'], $sum['out'], $sum['overdue'], $sum['incidents']] === [1, 2, 1, 1]
            && $sum['top'][0]['loans'] === 2 && $sum['top'][0]['qty'] === '4'
            && end($sum['monthly'])['count'] === 2);
    $this->actingAs($s['managerB'])->get(route('loans.reports.index'))->assertOk()
        ->assertViewHas('summary', fn (array $sum) => [$sum['pending'], $sum['out'], $sum['overdue'], $sum['incidents']] === [0, 1, 1, 0]);
    $this->actingAs($s['admin'])->get(route('loans.reports.index'))->assertOk()
        ->assertViewHas('summary', fn (array $sum) => [$sum['pending'], $sum['out'], $sum['overdue'], $sum['incidents']] === [1, 3, 2, 1]);
    $this->actingAs($s['admin'])->get(route('loans.reports.index', ['lab_id' => $s['labB']->id]))->assertOk()
        ->assertViewHas('summary', fn (array $sum) => [$sum['pending'], $sum['out'], $sum['overdue'], $sum['incidents']] === [0, 1, 1, 0]);

    // No person, no value on the dashboard.
    $html = $this->actingAs($s['managerA'])->get(route('loans.reports.index'))->getContent();
    expect($html)->not->toContain('ผู้ยืมสาขาเอ')->not->toContain('12345');
});

test('availability, movements, borrower summary and KPI agree with the tables', function () {
    $s = loanReportScene();
    $m = $s['managerA'];

    // Availability: one row per asset of the branch: 10 in all, 3 out (a1 2 + a2 1), 1 unusable (the pending damage).
    $avail = loanReportCsv($this, $m, 'availability');
    expect($avail)->toHaveCount(2);
    expect(array_slice($avail[1], 5, 4))->toBe(['10', '3', '1', '6']);
    expect(end($avail[0]))->toBe(__('loans.report.col_replacement_value'))->and(end($avail[1]))->toBe('12345.67');

    // Movements: exactly the branch's ledger rows, newest first.
    $moves = loanReportCsv($this, $m, 'movements');
    expect(count($moves) - 1)->toBe(LoanMovement::where('lab_id', $s['labA']->id)->count());
    expect(count(loanReportCsv($this, $s['admin'], 'movements')) - 1)->toBe(LoanMovement::count());

    // Borrower summary: the borrower of A, 2 documents handed out, 4 pieces.
    $sum = loanReportCsv($this, $m, 'borrower_summary');
    expect($sum)->toHaveCount(2);
    expect([$sum[1][1], $sum[1][4], $sum[1][5]])->toBe(['ผู้ยืมสาขาเอ ทดสอบรายงาน', '2', '4']);

    // KPI: 2 handed out, 0 closed, 1 late now -> 50.0 %, 0 "unknown".
    $kpi = collect(array_slice(loanReportCsv($this, $m, 'kpi'), 1))->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
    expect($kpi[__('loans.report.kpi_handed_out')])->toBe('2');
    expect($kpi[__('loans.report.kpi_closed')])->toBe('0');
    expect($kpi[__('loans.report.kpi_late')])->toBe('1');
    expect($kpi[__('loans.report.kpi_late_rate')])->toBe('50.0');
    expect($kpi[__('loans.report.kpi_unknown', ['days' => 30])])->toBe('0');
});

test('KPI "status unknown": overdue more than unknown_after_days with no movement or due change in that time', function () {
    config(['loans.unknown_after_days' => 5]);
    $s = loanReportScene();
    $this->travel(10)->days();      // a1 is now 13 days late, a2 3 days late; nothing happened to either for 10 days

    // Only a1 is late by MORE than 5 days; a2 is late but not yet "unknown".
    $kpi = collect(array_slice(loanReportCsv($this, $s['managerA'], 'kpi'), 1))->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
    expect($kpi[__('loans.report.kpi_unknown', ['days' => 5])])->toBe('1');
    expect($kpi[__('loans.report.kpi_late')])->toBe('2');
});

test('QA fix: KPI "returned late" is timed by the last piece coming back, not by closed_at (a result recorded after the due date is not the borrower\'s delay)', function () {
    [$lab, $asset] = loanBranch(10);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $counter = scientistUser(['lab_id' => $lab->id]);
    $resolver = auditorUser(['lab_id' => $lab->id]);

    // Both due in 7 days.
    $onTime = handedOutLoan($borrower, $asset, 1);
    $late = handedOutLoan($borrower, $asset, 1);
    // onTime: the piece comes back damaged TODAY (well before the due date); the case stays open.
    $incident = recordLoanIncident($onTime, $counter, 'DAMAGED', 1);

    $this->travel(10)->days();
    // late: the piece comes back usable 3 days after the due date.
    app(\App\Domain\Loan\Services\LoanCirculationService::class)->receiveReturn($counter->fresh(), $late->fresh(), [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    // onTime: the result of its case is recorded only now, so it CLOSES after the due date.
    app(\App\Domain\Loan\Services\LoanIncidentService::class)->resolve($resolver->fresh(), $incident->fresh(), ['resolution' => 'REPAIRED', 'condition_after' => 'GOOD', 'resolution_note' => 'ซ่อมแล้วใช้งานได้ตามปกติ']);

    expect([$onTime->fresh()->status, $late->fresh()->status])->toBe(['CLOSED', 'CLOSED']);
    expect($onTime->fresh()->closed_at->toDateString() > $onTime->fresh()->due_date->toDateString())->toBeTrue();

    $kpi = collect(array_slice(loanReportCsv($this, $resolver, 'kpi'), 1))->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);
    expect($kpi[__('loans.report.kpi_closed')])->toBe('2');
    expect($kpi[__('loans.report.kpi_late')])->toBe('1');              // `late` only; closed_at would have counted both
    expect($kpi[__('loans.report.kpi_late_rate')])->toBe('50.0');
});

test('QA fix PDPA: in the movement and monthly audit reports, a case of the viewer\'s OWN loan keeps its row but hides who recorded / approved, the reference, the letter and the remark; other rows and other viewers are unchanged', function () {
    [$lab, $asset] = loanBranch(10);
    $selfManager = auditorUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'phone_encrypted' => '0812345678', 'faculty' => 'วิทยาศาสตร์', 'profile_completed_at' => now()]);
    $colleague = auditorUser(['lab_id' => $lab->id]);
    $recorder = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZRECORDER ผู้รับคืน']);
    $resolver = labManagerUser(['lab_id' => $lab->id, 'full_name' => 'ZZRESOLVER ผู้บันทึกผล']);
    $receiver = scientistUser(['lab_id' => $lab->id, 'full_name' => 'ZZRECEIVER ผู้รับของทดแทน']);

    $own = handedOutLoan($selfManager, $asset, 2);
    $incident = recordLoanIncident($own, $recorder, 'LOST', 1);
    attachLoanIncidentFile($incident, $recorder);
    app(\App\Domain\Loan\Services\LoanIncidentService::class)->resolve($resolver->fresh(), $incident->fresh(), loanResolveData('REPLACED', ['receiver_id' => $receiver->id, 'external_ref' => 'ZZREF-2569/99']));
    $hidden = __('loans.report.hidden_own_case');

    // Movements: the three rows of the case (MARK_LOST, WRITE_OFF, REPLACE_IN) are there, with by / approver / reference hidden.
    $mine = collect(array_slice(loanReportCsv($this, $selfManager, 'movements'), 1));
    $caseTypes = [__('loans.movement_mark_lost'), __('loans.movement_write_off'), __('loans.movement_replace_in')];
    $caseRows = $mine->filter(fn ($r) => in_array($r[5], $caseTypes, true));
    expect($caseRows)->toHaveCount(3);
    foreach ($caseRows as $r) {
        expect([$r[11], $r[12], $r[13]])->toBe([$hidden, $hidden, $hidden]);
    }
    // A row that is not part of a case (the hand-out) keeps its columns.
    expect($mine->firstWhere(5, __('loans.movement_handout'))[11])->not->toBe($hidden);
    expect(json_encode($mine->all(), JSON_UNESCAPED_UNICODE))->not->toContain('ZZRESOLVER')->not->toContain('ZZRECEIVER')->not->toContain('ZZRECORDER')->not->toContain('ZZREF');

    // Monthly audit: same, plus the letter and the remark columns.
    $audit = array_slice(loanReportCsv($this, $selfManager, 'monthly_audit'), 1);
    expect($audit)->toHaveCount(2);
    foreach ($audit as $r) {
        expect($r[7])->toBe($own->doc_no);
        expect(array_slice($r, 8))->toBe([$hidden, $hidden, $hidden, $hidden, $hidden]);
    }
    $this->actingAs($selfManager)->get(route('loans.reports.show', 'monthly_audit'))->assertOk()->assertDontSee('ZZREF')->assertDontSee('ZZRESOLVER');
    $this->actingAs($selfManager)->get(route('loans.reports.show', 'movements'))->assertOk()->assertDontSee('ZZREF')->assertDontSee('ZZRECEIVER');

    // A colleague of the branch (neither borrower nor creator) sees everything.
    $theirs = json_encode(array_merge(loanReportCsv($this, $colleague, 'movements'), loanReportCsv($this, $colleague, 'monthly_audit')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($theirs)->toContain('ZZRESOLVER')->toContain('ZZRECEIVER')->toContain('ZZRECORDER')->toContain('ZZREF-2569/99')->not->toContain($hidden);
});

test('QA fix PDPA: the borrower summary has no free-text purpose detail, in the file or on the page', function () {
    [$lab, $asset] = loanBranch(10);
    $borrower = staffUser(['lab_id' => $lab->id]);
    handedOutLoan($borrower, $asset, 1, null, ['purpose_type' => 'OTHER', 'purpose_detail' => 'ZZDETAIL ถ่ายทำสื่อกับคุณสมมุติ']);
    $manager = auditorUser(['lab_id' => $lab->id]);

    $rows = loanReportCsv($this, $manager, 'borrower_summary');
    expect($rows[0])->toBe(array_map(fn ($c) => __('loans.report.col_'.$c), ['lab', 'borrower', 'borrower_status', 'purpose', 'docs', 'pieces']));
    expect($rows[1][3])->toBe(__('loans.purpose_other'));
    expect(json_encode($rows, JSON_UNESCAPED_UNICODE))->not->toContain('ZZDETAIL');
    $this->actingAs($manager)->get(route('loans.reports.show', 'borrower_summary'))->assertOk()->assertDontSee('ZZDETAIL');
});

test('the incident report lists the branch\'s cases, never one of a loan the viewer borrowed or typed in', function () {
    $s = loanReportScene();
    // A manager of A who borrowed something themselves, with an incident on it.
    $selfManager = auditorUser(['lab_id' => $s['labA']->id, 'person_type' => 'STAFF', 'phone_encrypted' => '0812345678', 'faculty' => 'วิทยาศาสตร์', 'profile_completed_at' => now()]);
    $own = handedOutLoan($selfManager, $s['assetA'], 1);
    $clerk = scientistUser(['lab_id' => $s['labA']->id]);
    recordLoanIncident($own, $clerk, 'LOST', 1);
    $clerk->update(['is_active' => false]);

    $docs = fn (array $rows) => collect(array_slice($rows, 1))->pluck(1)->sort()->values()->all();
    expect($docs(loanReportCsv($this, $s['managerA'], 'incidents')))->toBe(collect([$s['a2']->doc_no, $own->doc_no])->sort()->values()->all());
    expect($docs(loanReportCsv($this, $selfManager, 'incidents')))->toBe([$s['a2']->doc_no]);
    expect($docs(loanReportCsv($this, $s['managerB'], 'incidents')))->toBe([]);
    expect($docs(loanReportCsv($this, $s['managerA'], 'incidents', ['kind' => 'LOST'])))->toBe([$own->doc_no]);
    $this->actingAs($selfManager)->get(route('loans.reports.show', 'incidents'))->assertOk()->assertDontSee($own->doc_no);

    // No description of what happened anywhere in the report.
    $csv = json_encode(loanReportCsv($this, $s['managerA'], 'incidents'), JSON_UNESCAPED_UNICODE);
    expect($csv)->not->toContain('หน้าจอแตกร้าว');
});

test('prices / values are only for holders of loan.asset_manage: a loan.report-only account gets neither the column nor the number, on the page or in the file', function () {
    $s = loanReportScene();
    $reader = userWithPermissions(['loan.report'], ['lab_id' => $s['labA']->id]);
    expect($reader->hasPermission('loan.asset_manage'))->toBeFalse();
    // The value of an incident is fixed when its result is recorded; give the pending one a value so the column has one.
    DB::table('loan_incidents')->where('id', $s['incident']->id)->update(['value_snapshot' => '12345.67']);

    foreach (['availability', 'incidents'] as $report) {
        $rows = loanReportCsv($this, $reader, $report);
        expect(count($rows))->toBeGreaterThan(1, $report);
        expect($rows[0])->not->toContain(__('loans.report.col_replacement_value'))->not->toContain(__('loans.report.col_value'));
        expect(json_encode($rows))->not->toContain('12345');
        $this->actingAs($reader)->get(route('loans.reports.show', $report))->assertOk()
            ->assertDontSee('12345')->assertDontSee(__('loans.report.col_replacement_value'))->assertDontSee(__('loans.report.col_value'));

        // The manager does get them.
        $managerRows = loanReportCsv($this, $s['managerA'], $report);
        expect(json_encode($managerRows))->toContain('12345.67');
    }
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->where('user_id', $reader->id)->get()->every(fn ($a) => $a->new_value['with_values'] === false))->toBeTrue();
});

// --- the monthly audit report and the self-actions report -------------------------------------------------------------

test('the monthly audit report lists every WRITE_OFF / ADJUST / REPLACE_IN of the month with remarks for a missing, short or reused reference', function () {
    [$lab, $manager, $approver] = loanManagers();
    $p1 = registerLoanAsset($manager, $lab, 'POOL', 5);
    $p2 = registerLoanAsset($manager, $lab, 'POOL', 5);
    $p3 = registerLoanAsset($manager, $lab, 'POOL', 5);
    $p4 = registerLoanAsset($manager, $lab, 'POOL', 5);
    $note = 'นับใหม่พบของเพิ่มในตู้';

    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00'));
    loanService()->adjust($manager, $p1, 'IN', 1, $note, 'MEMO-0001', $approver->id);
    loanService()->adjust($manager, $p2, 'IN', 1, $note, 'memo-0001 ', $approver->id);   // the same reference (case / spaces)
    loanService()->adjust($manager, $p3, 'IN', 1, $note, 'AB', $approver->id);
    loanService()->adjust($manager, $p4, 'OUT', 1, $note, null, $approver->id);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00'));
    loanService()->adjust($manager, $p1, 'IN', 1, $note, 'NEXT-MONTH-1', $approver->id);   // another month

    $rows = array_slice(loanReportCsv($this, $manager, 'monthly_audit', ['month' => '2026-10']), 1);
    expect($rows)->toHaveCount(4);
    $byRef = collect($rows)->keyBy(10);
    expect($byRef['MEMO-0001'][12])->toBe(__('loans.report.ref_duplicate'));
    expect($byRef['memo-0001'][12])->toBe(__('loans.report.ref_duplicate'));
    expect($byRef['AB'][12])->toBe(__('loans.report.ref_short'));
    expect($byRef[''][12])->toBe(__('loans.report.ref_missing'));
    expect($byRef[''][5])->toBe(__('loans.movement_adjust_out'));
    // Who recorded and who approved are named (that is the point of the report); an adjustment has no file.
    expect([$byRef['AB'][8], $byRef['AB'][9], $byRef['AB'][11]])->toBe([$manager->full_name, $approver->full_name, __('loans.report.file_na')]);

    // Another branch's manager sees none of it; ADMIN sees it.
    expect(array_slice(loanReportCsv($this, navUser('auditor'), 'monthly_audit', ['month' => '2026-10']), 1))->toBe([]);
    expect(array_slice(loanReportCsv($this, navUser('admin', ['lab_id' => null]), 'monthly_audit', ['month' => '2026-10']), 1))->toHaveCount(4);
    // The current month by default.
    expect(array_slice(loanReportCsv($this, $manager, 'monthly_audit'), 1))->toHaveCount(1);
});

test('the monthly audit report shows a write-off of an incident with "letter attached: yes" and no remark for a proper reference', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $resolver = auditorUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    $lost = recordLoanIncident($loan, $clerk, 'LOST', 1, 1);
    attachLoanIncidentFile($lost, $resolver);
    $clerk->update(['is_active' => false]);
    app(\App\Domain\Loan\Services\LoanIncidentService::class)->resolve($resolver->fresh(), $lost->fresh(), loanResolveData('WAIVED', ['external_ref' => 'อว 7301/0999']));

    $rows = array_slice(loanReportCsv($this, $resolver, 'monthly_audit'), 1);
    expect($rows)->toHaveCount(1);
    expect([$rows[0][5], $rows[0][10], $rows[0][11], $rows[0][12]])->toBe([__('loans.movement_write_off'), 'อว 7301/0999', __('loans.report.file_yes'), '']);
});

test('the self-actions report lists the month\'s LOAN_SELF_* rows of the branch only — `_` in the pattern is literal, so look-alike actions stay out', function () {
    $s = loanReportScene();
    $record = fn (string $action, Loan $loan) => AuditLog::record(action: $action, userId: $s['managerA']->id, username: $s['managerA']->username, entityType: Loan::class, entityId: $loan->id, newValue: ['self' => true]);

    $record('LOAN_SELF_HANDOUT', $s['a1']);
    $record('LOAN_SELF_RENEW_APPROVE', $s['a2']);
    $record('LOANXSELFXHANDOUT', $s['a1']);        // would match an unescaped 'LOAN_SELF_%'
    $record('LOAN_HANDOUT', $s['a1']);
    $record('LOAN_SELF_RETURN', $s['b1']);         // another branch

    $rows = array_slice(loanReportCsv($this, $s['managerA'], 'self_actions'), 1);
    expect(collect($rows)->pluck(4)->sort()->values()->all())->toBe(['LOAN_SELF_HANDOUT', 'LOAN_SELF_RENEW_APPROVE']);
    expect(collect($rows)->firstWhere(4, 'LOAN_SELF_HANDOUT')[3])->toBe(__('loans.report.self_handout'));
    expect(collect(array_slice(loanReportCsv($this, $s['admin'], 'self_actions'), 1))->pluck(4)->sort()->values()->all())
        ->toBe(['LOAN_SELF_HANDOUT', 'LOAN_SELF_RENEW_APPROVE', 'LOAN_SELF_RETURN']);
    expect(array_slice(loanReportCsv($this, $s['managerA'], 'self_actions', ['month' => CarbonImmutable::today()->subMonth()->format('Y-m')]), 1))->toBe([]);
});

// --- export: audit and CSV injection ---------------------------------------------------------------------------------

test('every export writes one LOAN_REPORT_EXPORT row first — report, branch, filters, row count — and never the text typed into a search box', function () {
    $s = loanReportScene();

    loanReportCsv($this, $s['managerA'], 'outstanding', ['borrower' => 'ผู้ยืมสาขาเอ', 'item' => 'อุปกรณ์', 'min_days' => 1]);

    $audit = AuditLog::where('action', 'LOAN_REPORT_EXPORT')->sole();
    expect($audit->user_id)->toBe($s['managerA']->id)->and($audit->username)->toBe($s['managerA']->username);
    expect($audit->new_value)->toBe([
        'report' => 'outstanding',
        'format' => 'csv',
        'lab' => (string) $s['labA']->id,
        'filters' => ['from' => null, 'to' => null, 'month' => null, 'min_days' => 1, 'kind' => null, 'borrower_filter' => true, 'item_filter' => true],
        'rows' => 1,
        'truncated' => false,
        'with_values' => true,
    ]);
    $stored = json_encode([$audit->new_value, $audit->old_value, $audit->message], JSON_UNESCAPED_UNICODE);
    expect($stored)->not->toContain('ผู้ยืมสาขาเอ')->not->toContain('อุปกรณ์');

    // ADMIN over every branch is recorded as "all".
    loanReportCsv($this, $s['admin'], 'availability');
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->latest('id')->first()->new_value['lab'])->toBe('all');

    // Opening a report page is not an export: no row.
    $this->actingAs($s['managerA'])->get(route('loans.reports.show', 'outstanding'))->assertOk();
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->count())->toBe(2);
});

test('SEC-IN-10: a cell starting with = + - @ comes out with a leading apostrophe in every export (names, tags, references), headings included', function () {
    [$lab, $asset] = loanBranch(10);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $names = ['=HYPERLINK("http://evil.example","x")', '+1+1', '-2+3', '@SUM(1,1)'];
    foreach ($names as $name) {
        handedOutLoan(staffUser(['lab_id' => $lab->id, 'full_name' => $name]), $asset, 1);
    }
    DB::table('items')->where('id', $asset->item_id)->update(['name_th' => '=cmd|calc']);

    $rows = loanReportCsv($this, $manager, 'outstanding');
    $borrowers = collect(array_slice($rows, 1))->pluck(2)->sort()->values()->all();
    expect($borrowers)->toBe(collect($names)->map(fn ($n) => "'".$n)->sort()->values()->all());
    expect(collect(array_slice($rows, 1))->pluck(4)->unique()->all())->toBe(["'=cmd|calc"]);

    // No cell of any export starts with a formula character.
    foreach (LoanReportService::keys() as $report) {
        foreach (loanReportCsv($this, $manager, $report) as $row) {
            foreach ($row as $cell) {
                expect($cell === '' || ! in_array($cell[0], ['=', '+', '-', '@'], true))->toBeTrue("{$report}: {$cell}");
            }
        }
    }
});

test('[DECISION] SEC-IN-10 and a leading TAB / CR: spreadsheet guidance (OWASP) also neutralises these; the spec lists only = + - @', function () {
    [$lab, $asset] = loanBranch(10);
    $manager = auditorUser(['lab_id' => $lab->id]);
    handedOutLoan(staffUser(['lab_id' => $lab->id, 'full_name' => "\t=1+1"]), $asset, 1);
    handedOutLoan(staffUser(['lab_id' => $lab->id, 'full_name' => "\r=1+1"]), $asset, 1);

    $borrowers = collect(array_slice(loanReportCsv($this, $manager, 'outstanding'), 1))->pluck(2)->all();
    foreach ($borrowers as $cell) {
        expect($cell[0])->toBe("'");
    }
})->skip('Waiting for a decision: CsvInjectionGuard (shared by every export) follows the spec (= + - @). The brief asked for TAB too; see the report to the secretary.');

// --- LIKE: the two report search boxes --------------------------------------------------------------------------------

test('`%`, `_` and a backslash in the report search boxes (borrower, item) are literal characters, never wildcards', function () {
    $s = loanReportScene();
    $m = $s['managerA'];

    // (A single `_` is not tried in the borrower box: that box also searches the username, and the test usernames really
    // contain one — `user_...` — so it must match them literally.)
    foreach (['%', '%%', '_', '___', '\\', '%'.mb_substr('ผู้ยืม', 0, 1)] as $term) {
        if ($term !== '_') {
            expect(loanReportCsv($this, $m, 'outstanding', ['borrower' => $term]))->toHaveCount(1, "borrower {$term}");
        }
        foreach (['outstanding', 'availability', 'movements'] as $report) {
            expect(loanReportCsv($this, $m, $report, ['item' => $term]))->toHaveCount(1, "{$report} item {$term}");
        }
    }
    // Ordinary text still finds by part of the value.
    expect(loanReportCsv($this, $m, 'outstanding', ['borrower' => 'ยืมสาขา']))->toHaveCount(3);
    expect(loanReportCsv($this, $m, 'availability', ['item' => mb_substr('อุปกรณ์ให้ยืมทดสอบ', 2, 5)]))->toHaveCount(2);
});

test('the report pages render every report for a manager and for ADMIN, with a label for each field and keyboard-focusable tables', function () {
    $s = loanReportScene();

    foreach ([$s['managerA'], $s['admin']] as $user) {
        $pages = [route('loans.reports.index')];
        foreach (LoanReportService::keys() as $report) {
            $pages[] = route('loans.reports.show', $report);
        }
        foreach ($pages as $url) {
            $xp = htmlXPath($this->actingAs($user)->get($url)->assertOk()->getContent());
            foreach ($xp->query('//label[@for]') as $label) {
                expect($xp->query('//*[@id="'.$label->getAttribute('for').'"]')->length)->toBeGreaterThanOrEqual(1, $url);
            }
            foreach ($xp->query('//*[contains(@class, "overflow-x-auto")]') as $scroller) {
                expect($scroller->hasAttribute('tabindex'))->toBeTrue($url);
            }
        }
    }

    // The reports page sits in the loan card with the "reports" tab marked.
    $nav = layoutNav($this->actingAs($s['managerA'])->get(route('loans.reports.show', 'kpi'))->getContent());
    expect($nav['title'])->toBe(__('nav.card_loan'));
    $active = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
    expect($active)->toHaveCount(1)->and($active[0]['label'])->toBe(__('nav.tab_loan_reports'));
});

test('report filters are validated: a bad date, month, number or kind is refused and nothing is exported', function () {
    $manager = navUser('auditor');

    foreach ([['from' => '2026-13-01'], ['to' => 'yesterday'], ['from' => '2026-11-10', 'to' => '2026-11-01'], ['month' => '2026-1'], ['min_days' => -1], ['min_days' => 'x'], ['kind' => 'STOLEN'], ['borrower' => str_repeat('ก', 101)]] as $query) {
        $this->actingAs($manager)->get(route('loans.reports.export', array_merge(['report' => 'outstanding'], $query)))->assertSessionHasErrors();
    }
    expect(AuditLog::where('action', 'LOAN_REPORT_EXPORT')->count())->toBe(0);
});
