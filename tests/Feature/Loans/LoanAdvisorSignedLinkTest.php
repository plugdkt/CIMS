<?php

use App\Domain\Loan\Services\LoanNotifier;
use App\Mail\LoanAdvisorApprovalMail;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 2, FR-LN-12: the advisor's emailed 72-hour signed link (public, no login). The signed URL is the
 * credential; LoanApprovalService decides whether that advisor may really act. Covered here: a good link, an
 * expired / forged / re-pointed one, reuse after a decision, the ineligible-advisor cases, the throttle, and that
 * the email carries no personal data about the borrower.
 *
 * (Written without being run: see the report to the secretary.)
 */

beforeEach(function () {
    Mail::fake();
});

if (! function_exists('loanSignedUrl')) {
    function loanSignedUrl(Loan $loan, int $hours = 72): string
    {
        return URL::temporarySignedRoute('loans.approve.signed', now()->addHours($hours), ['loan' => $loan->ulid]);
    }
}

if (! function_exists('studentLoanWaitingForAdvisor')) {
    /** @return array{Loan, User, User} the SUBMITTED loan, its student and its advisor */
    function studentLoanWaitingForAdvisor(): array
    {
        [$lab, $asset] = loanBranch();
        $student = studentUser(['lab_id' => $lab->id, 'full_name' => 'ZZ นิสิตสมมุติ นามสมมุติ']);
        $loan = submittedLoan($student, $asset, 2);

        return [$loan, $student, User::findOrFail($student->advisor_id)];
    }
}

test('a valid signed link shows the document to decide: number, what is asked, dates', function () {
    [$loan] = studentLoanWaitingForAdvisor();

    $this->get(loanSignedUrl($loan))->assertOk()->assertSee($loan->doc_no)->assertSee('อุปกรณ์ให้ยืมทดสอบ');
});

test('an unsigned, tampered or re-pointed link is refused (403)', function () {
    [$loan, , $advisor] = studentLoanWaitingForAdvisor();
    [$otherLoan] = studentLoanWaitingForAdvisor();
    $url = loanSignedUrl($loan);

    // No signature at all.
    $this->get('/loan-approve/'.$loan->ulid)->assertForbidden();
    $this->post('/loan-approve/'.$loan->ulid, ['decision' => 'APPROVE'])->assertForbidden();

    // A forged signature, and a signature with one character changed.
    $this->get('/loan-approve/'.$loan->ulid.'?expires='.now()->addHours(72)->timestamp.'&signature='.str_repeat('a', 64))->assertForbidden();
    $changed = substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a');
    $this->get($changed)->assertForbidden();

    // The valid signature of one loan does not open another loan.
    $other = str_replace($loan->ulid, $otherLoan->ulid, $url);
    $this->get($other)->assertForbidden();
    $this->post($other, ['decision' => 'APPROVE'])->assertForbidden();

    // Extending the expiry invalidates the signature.
    $this->get(preg_replace('/expires=\d+/', 'expires='.now()->addYears(5)->timestamp, $url))->assertForbidden();

    expect($loan->fresh()->status)->toBe('SUBMITTED');
    expect($otherLoan->fresh()->status)->toBe('SUBMITTED');
    expect(LoanApproval::count())->toBe(0);
    expect($advisor->id)->not->toBeNull();
});

test('an expired link (past 72 hours) is refused for both the page and the decision', function () {
    [$loan] = studentLoanWaitingForAdvisor();
    $url = loanSignedUrl($loan);

    $this->travel(73)->hours();

    $this->get($url)->assertForbidden();
    $this->post($url, ['decision' => 'APPROVE'])->assertForbidden();
    expect($loan->fresh()->status)->toBe('SUBMITTED');
});

test('approving through the link moves the student loan to ADVISOR_APPROVED (the manager step still follows) and records the advisor', function () {
    [$loan, , $advisor] = studentLoanWaitingForAdvisor();

    $response = $this->post(loanSignedUrl($loan), ['decision' => 'APPROVE'])->assertOk();

    expect($response->viewData('failed'))->toBeFalse();
    expect($response->viewData('status'))->toBe(__('loans.advisor_decision_recorded'));
    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('ADVISOR_APPROVED')->and($fresh->advisor_signed_at)->not->toBeNull();
    $row = LoanApproval::where('loan_id', $loan->id)->firstOrFail();
    expect($row->step)->toBe('ADVISOR')->and($row->actor_id)->toBe($advisor->id)->and($row->decision)->toBe('APPROVE');
    expect(loanAudit('LOAN_ADVISOR_APPROVE', $loan))->toHaveCount(1);
});

test('rejecting through the link needs a reason; with one the loan is REJECTED', function () {
    [$loan] = studentLoanWaitingForAdvisor();

    $this->post(loanSignedUrl($loan), ['decision' => 'REJECT'])->assertSessionHasErrors('reason');
    expect($loan->fresh()->status)->toBe('SUBMITTED');

    $this->post(loanSignedUrl($loan), ['decision' => 'REJECT', 'reason' => 'ยังไม่เหมาะสม'])->assertOk();
    expect($loan->fresh()->status)->toBe('REJECTED');
    expect(LoanApproval::where('loan_id', $loan->id)->firstOrFail()->decision)->toBe('REJECT');
});

test('a link cannot be used twice: after one decision the same URL changes nothing, and the page says it is no longer waiting', function () {
    [$loan] = studentLoanWaitingForAdvisor();
    $url = loanSignedUrl($loan);

    $this->post($url, ['decision' => 'APPROVE'])->assertOk();

    // Replay with the opposite decision.
    $replay = $this->post($url, ['decision' => 'REJECT', 'reason' => 'เปลี่ยนใจ'])->assertOk();
    expect($replay->viewData('failed'))->toBeTrue();
    expect($replay->viewData('status'))->toBe(__('loans.error.invalid_transition'));
    expect($this->post($url, ['decision' => 'APPROVE'])->assertOk()->viewData('failed'))->toBeTrue();

    expect($loan->fresh()->status)->toBe('ADVISOR_APPROVED');
    expect(LoanApproval::where('loan_id', $loan->id)->count())->toBe(1);
    expect(loanAudit('LOAN_ADVISOR_APPROVE', $loan))->toHaveCount(1);
    expect(loanAudit('LOAN_ADVISOR_REJECT', $loan))->toHaveCount(0);

    $this->get($url)->assertOk()->assertSee(__('loans.signed_not_waiting'))->assertDontSee($loan->doc_no);
});

test('a link for a cancelled or already manager-decided request shows nothing to decide', function () {
    [$loan] = studentLoanWaitingForAdvisor();
    $url = loanSignedUrl($loan);
    forceLoanStatus($loan, 'CANCELLED');

    $this->get($url)->assertOk()->assertSee(__('loans.signed_not_waiting'));
    $response = $this->post($url, ['decision' => 'APPROVE'])->assertOk();
    expect($response->viewData('failed'))->toBeTrue();
    expect($loan->fresh()->status)->toBe('CANCELLED');
});

test('a link for a non-student\'s request (no advisor step) decides nothing', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $url = loanSignedUrl($loan);

    $this->get($url)->assertOk()->assertSee(__('loans.signed_not_waiting'));
    $response = $this->post($url, ['decision' => 'APPROVE'])->assertOk();

    expect($response->viewData('failed'))->toBeTrue();
    expect($response->viewData('status'))->toBe(__('loans.error.advisor_not_eligible'));
    expect($loan->fresh()->status)->toBe('SUBMITTED');
    expect(LoanApproval::count())->toBe(0);
});

test('an ineligible advisor cannot decide through the link: same generic message, document untouched', function (string $cause) {
    [$loan, $student, $advisor] = studentLoanWaitingForAdvisor();
    match ($cause) {
        'deactivated' => $advisor->update(['is_active' => false]),
        'permission revoked' => $advisor->roles()->detach(Role::where('code', 'ADVISOR')->firstOrFail()),
        'the borrower named as their own advisor' => (function () use ($student, $loan) {
            $student->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());
            DB::table('loans')->where('id', $loan->id)->update(['advisor_id' => $student->id]);
        })(),
    };
    $url = loanSignedUrl($loan->fresh());

    foreach ([['decision' => 'APPROVE'], ['decision' => 'REJECT', 'reason' => 'ไม่เหมาะสม']] as $payload) {
        $response = $this->post($url, $payload)->assertOk();
        expect($response->viewData('failed'))->toBeTrue();
        expect($response->viewData('status'))->toBe(__('loans.error.advisor_not_eligible'));
    }

    expect($loan->fresh()->status)->toBe('SUBMITTED')->and($loan->fresh()->advisor_signed_at)->toBeNull();
    expect(LoanApproval::where('loan_id', $loan->id)->count())->toBe(0);
})->with(['deactivated', 'permission revoked', 'the borrower named as their own advisor']);

test('the link is throttled: ten requests a minute, then 429 (it is a public URL)', function () {
    [$loan] = studentLoanWaitingForAdvisor();
    $url = loanSignedUrl($loan);

    for ($i = 1; $i <= 10; $i++) {
        $this->get($url)->assertOk();
    }
    $this->get($url)->assertStatus(429);
    // The decision route shares the limiter: a flood of GETs does not leave a free POST.
    $this->post($url, ['decision' => 'APPROVE'])->assertStatus(429);
    expect($loan->fresh()->status)->toBe('SUBMITTED');
});

test('the signed link (GET and POST) is the only loan URL without login: it needs `signed` and a throttle, every other loans.* route needs `auth`', function () {
    $all = collect(app('router')->getRoutes()->getRoutes());

    $public = $all->filter(fn ($route) => str_starts_with($route->uri(), 'loan-approve/'));
    expect($public)->toHaveCount(2);
    foreach ($public as $route) {
        $middleware = $route->gatherMiddleware();
        expect(in_array('signed', $middleware, true))->toBeTrue();
        expect(in_array('throttle:10,1', $middleware, true))->toBeTrue();
        expect(in_array('auth', $middleware, true))->toBeFalse();
    }

    $private = $all->filter(fn ($route) => str_starts_with($route->uri(), 'loans'));
    expect($private->count())->toBeGreaterThan(15);
    foreach ($private as $route) {
        expect(in_array('auth', $route->gatherMiddleware(), true))->toBeTrue($route->uri());
    }
});

// --- the email (N1) --------------------------------------------------------------------------------------------

test('submitting a student\'s request emails the advisor a signed link once; the email carries no name, phone or student code of the borrower', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id, 'full_name' => 'ZZ นิสิตสมมุติ นามสมมุติ', 'username' => 'zz.nisit.secret']);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 2);

    app(LoanNotifier::class)->submitted($loan);

    $html = null;
    Mail::assertQueued(LoanAdvisorApprovalMail::class, function ($mail) use ($advisor, $loan, &$html) {
        if (! $mail->hasTo($advisor->email) || $mail->loan->id !== $loan->id) {
            return false;
        }
        $html = $mail->render();

        return true;
    });
    Mail::assertQueuedCount(1);

    expect($html)->toContain($loan->doc_no)->toContain('อุปกรณ์ให้ยืมทดสอบ');
    foreach ([$student->full_name, 'ZZ นิสิตสมมุติ', 'zz.nisit.secret', '0812345678', '6512345', (string) $student->email] as $leak) {
        expect($html)->not->toContain($leak);
    }

    // The link inside it really opens the decision page.
    expect(preg_match('/href="([^"]*loan-approve[^"]*)"/', (string) $html, $m))->toBe(1);
    $this->get(html_entity_decode($m[1]))->assertOk()->assertSee($loan->doc_no);
});

test('the in-system notice to the advisor and the manager notices carry no personal data either', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id, 'full_name' => 'ZZ นิสิตสมมุติ นามสมมุติ', 'username' => 'zz.nisit.secret']);
    scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($student, $asset, 2);

    app(LoanNotifier::class)->submitted($loan);

    $notice = Notification::where('type', LoanNotifier::TYPE_PENDING_ADVISOR)->firstOrFail();
    $text = $notice->title.' '.$notice->body;
    expect($notice->user_id)->toBe($student->advisor_id)->and($notice->body)->toContain($loan->doc_no);
    foreach (['ZZ นิสิตสมมุติ', 'zz.nisit.secret', '0812345678', '6512345'] as $leak) {
        expect($text)->not->toContain($leak);
    }
});
