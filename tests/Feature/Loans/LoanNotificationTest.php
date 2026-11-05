<?php

use App\Domain\Loan\Services\LoanNotifier;
use App\Mail\LoanAdvisorApprovalMail;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 2, spec section 10 (N1-N3): who is told, and who is not. The rule everywhere is the same as
 * LoanAccess::isApproverFor: an ACTIVE holder of loan.approve whose users.lab_id equals the document's lab_id
 * (never another branch, never a user with no branch, never ADMIN unless nobody qualifies). In-system to all of them,
 * email only to the warehouse managers (LAB_MANAGER / AUDITOR), like requisitions. Nothing is sent before the
 * decision is saved, and a failure to notify never undoes it. No message carries a name, phone or student code.
 *
 * (Written without being run: see the report to the secretary.)
 */

beforeEach(function () {
    Mail::fake();
});

if (! function_exists('loanRoster')) {
    /**
     * A branch roster of people the notices must / must not reach.
     *
     * @return array<string, \App\Models\User>
     */
    function loanRoster(\App\Models\Lab $lab): array
    {
        $other = makeLab();

        return [
            'scientist' => scientistUser(['lab_id' => $lab->id]),
            'scientist2' => scientistUser(['lab_id' => $lab->id]),
            'manager' => auditorUser(['lab_id' => $lab->id]),
            'inactive' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
            'other_scientist' => scientistUser(['lab_id' => $other->id]),
            'other_manager' => auditorUser(['lab_id' => $other->id]),
            'no_branch' => scientistUser(['lab_id' => null]),
            'admin' => adminUser(['lab_id' => $lab->id]),
            'staff' => staffUser(['lab_id' => $lab->id]),
        ];
    }
}

if (! function_exists('assertNoNoticeFor')) {
    function assertNoNoticeFor(array $people): void
    {
        foreach ($people as $name => $user) {
            expect(Notification::where('user_id', $user->id)->count())->toBe(0, "{$name} must not be told");
            expect(loanMailCount($user))->toBe(0, "{$name} must not be emailed");
        }
    }
}

// --- N2: a request waits for a manager ---------------------------------------------------------------------------

test('N2: submitting a non-student request tells every ACTIVE approver of the branch in the system, emails only the warehouse manager, and nobody else', function () {
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $borrower = $people['staff'];

    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();

    $notices = loanNotices(LoanNotifier::TYPE_PENDING_MANAGER);
    expect($notices->pluck('user_id')->sort()->values()->all())->toBe(collect([$people['scientist']->id, $people['scientist2']->id, $people['manager']->id])->sort()->values()->all());
    foreach ($notices as $notice) {
        expect($notice->link_url)->toBe(route('loans.show', $loan))->and($notice->body)->toContain($loan->doc_no);
    }

    expect(loanMailCount($people['manager']))->toBe(1);
    expect(loanMailCount($people['scientist']))->toBe(0)->and(loanMailCount($people['scientist2']))->toBe(0);

    // Not inactive, not another branch, not a user with no branch, not ADMIN while approvers exist, not the borrower.
    assertNoNoticeFor(Arr::only($people, ['inactive', 'other_scientist', 'other_manager', 'no_branch', 'admin', 'staff']));
});

test('N2: the borrower is not told about their own request even when they are an approver of the branch', function () {
    [$lab, $asset] = loanBranch();
    $me = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $colleague = scientistUser(['lab_id' => $lab->id]);

    $this->actingAs($me)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']));

    expect(Notification::where('user_id', $me->id)->count())->toBe(0);
    expect(loanNotices(LoanNotifier::TYPE_PENDING_MANAGER)->pluck('user_id')->all())->toBe([$colleague->id]);
});

test('N2: when nobody in the branch can approve, every ADMIN is told to fix the roster (in the system and by email) and nobody else is', function () {
    [$lab, $asset] = loanBranch();
    $admin = adminUser(['lab_id' => null]);
    $otherAdmin = adminUser(['lab_id' => makeLab()->id]);
    $outsider = scientistUser(['lab_id' => makeLab()->id]);   // approves another branch only
    $borrower = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']));

    // Every ADMIN, and only ADMINs (the test database may already hold other ADMIN accounts).
    $ids = loanNotices(LoanNotifier::TYPE_NO_APPROVER)->pluck('user_id')->all();
    expect($ids)->toContain($admin->id)->toContain($otherAdmin->id);
    foreach ($ids as $id) {
        expect(User::findOrFail($id)->hasRole('ADMIN'))->toBeTrue();
    }
    expect(loanMailCount($admin))->toBe(1)->and(loanMailCount($otherAdmin))->toBe(1);
    expect(loanNotices(LoanNotifier::TYPE_PENDING_MANAGER))->toHaveCount(0);
    assertNoNoticeFor([$outsider, $borrower]);
});

// --- N1: a student's request waits for the advisor ---------------------------------------------------------------

test('N1: a student\'s request goes to the named advisor (signed link by email, a notice in the system) and NOT to the branch yet', function () {
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);

    $this->actingAs($student)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();

    Mail::assertQueued(LoanAdvisorApprovalMail::class, fn ($mail) => $mail->hasTo($advisor->email) && $mail->loan->id === $loan->id);
    Mail::assertQueuedCount(1);
    expect(loanNotices(LoanNotifier::TYPE_PENDING_ADVISOR)->pluck('user_id')->all())->toBe([$advisor->id]);
    expect(loanNotices(LoanNotifier::TYPE_PENDING_MANAGER))->toHaveCount(0);
    assertNoNoticeFor(Arr::only($people, ['scientist', 'scientist2', 'manager', 'other_scientist', 'admin']));
});

test('N1: nothing goes out to an advisor whose account is inactive', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($student, $asset, 1);
    User::findOrFail($student->advisor_id)->update(['is_active' => false]);

    app(LoanNotifier::class)->submitted($loan->fresh());

    Mail::assertNothingQueued();
    expect(Notification::count())->toBe(0);
});

test('N2 after the advisor, ONLY with the old two-step switch on (config true): the branch\'s approvers are told, the borrower is not (nothing decided yet)', function () {
    config(['loans.student_needs_manager_step' => true]);
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 1);

    loanAdvisorPost($this, $advisor, $loan)->assertRedirect();

    expect(loanNotices(LoanNotifier::TYPE_PENDING_MANAGER)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$people['scientist']->id, $people['scientist2']->id, $people['manager']->id])->sort()->values()->all());
    expect(Notification::where('user_id', $student->id)->count())->toBe(0);
    assertNoNoticeFor(Arr::only($people, ['inactive', 'other_scientist', 'other_manager', 'no_branch', 'admin']));
});

test('an advisor rejection goes to the borrower only (in the system and by email); the branch is not told', function () {
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 1);

    loanAdvisorPost($this, $advisor, $loan, ['decision' => 'REJECT', 'reason' => 'ยังไม่ถึงเวลาใช้งาน'])->assertRedirect();

    $notices = loanNotices(LoanNotifier::TYPE_DECISION);
    expect($notices->pluck('user_id')->all())->toBe([$student->id]);
    expect($notices[0]->title)->toBe(__('loans.notify.rejected_title', ['doc_no' => $loan->doc_no]));
    expect(loanMailCount($student))->toBe(1);
    assertNoNoticeFor(Arr::only($people, ['scientist', 'scientist2', 'manager', 'other_scientist', 'admin']));
});

test('CEO 2026-11-05 (default switch false): the advisor\'s approval is the final one, the borrower is told it is approved (system + email), N2 is NOT sent to the branch', function () {
    expect(config('loans.student_needs_manager_step'))->toBeFalse();
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 1);

    loanAdvisorPost($this, $advisor, $loan)->assertRedirect();

    $notices = loanNotices(LoanNotifier::TYPE_DECISION);
    expect($notices->pluck('user_id')->all())->toBe([$student->id]);
    expect($notices[0]->title)->toBe(__('loans.notify.approved_title', ['doc_no' => $loan->doc_no]));
    expect(loanNotices(LoanNotifier::TYPE_PENDING_MANAGER))->toHaveCount(0);
    expect(loanMailCount($student))->toBe(1);
    assertNoNoticeFor(Arr::only($people, ['scientist', 'scientist2', 'manager', 'other_scientist', 'other_manager', 'admin']));
});

// --- N3: the decision -----------------------------------------------------------------------------------------------

test('N3: the manager\'s approval or rejection goes to the borrower (in the system and by email) and to nobody else', function (string $decision) {
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $borrower = $people['staff'];
    $loan = submittedLoan($borrower, $asset, 1);
    $payload = $decision === 'APPROVE' ? ['decision' => 'APPROVE'] : ['decision' => 'REJECT', 'reason' => 'ของไม่ว่าง'];

    loanManagerPost($this, $people['scientist'], $loan, $payload)->assertRedirect();

    $notices = loanNotices(LoanNotifier::TYPE_DECISION);
    expect($notices->pluck('user_id')->all())->toBe([$borrower->id]);
    expect($notices[0]->title)->toBe(__($decision === 'APPROVE' ? 'loans.notify.approved_title' : 'loans.notify.rejected_title', ['doc_no' => $loan->doc_no]));
    expect($notices[0]->link_url)->toBe(route('loans.show', $loan));
    expect(loanMailCount($borrower))->toBe(1);
    assertNoNoticeFor(Arr::only($people, ['scientist', 'scientist2', 'manager', 'other_scientist', 'other_manager', 'admin']));
})->with(['APPROVE', 'REJECT']);

test('N3: nobody is notified of their own decision (approving one\'s own request) and the audit trail still has it', function () {
    [$lab, $asset] = loanBranch();
    $me = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $loan = submittedLoan($me, $asset, 1);

    loanManagerPost($this, $me, $loan)->assertRedirect();

    expect(Notification::where('user_id', $me->id)->count())->toBe(0);
    expect(loanMailCount($me))->toBe(0);
    expect(loanAudit('LOAN_SELF_APPROVE', $loan))->toHaveCount(1);
});

test('a cancellation by a manager tells the borrower; by the borrower themself tells nobody', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $byManager = submittedLoan($borrower, $asset, 1);
    $byBorrower = submittedLoan($borrower, $asset, 1);

    loanCancelPost($this, $scientist, $byManager, ['reason' => 'ของชำรุดทั้งหมด'])->assertRedirect();
    $notices = loanNotices(LoanNotifier::TYPE_CANCELLED);
    expect($notices->pluck('user_id')->all())->toBe([$borrower->id]);
    expect($notices[0]->title)->toBe(__('loans.notify.cancelled_title', ['doc_no' => $byManager->doc_no]));
    expect(loanMailCount($borrower))->toBe(1);

    loanCancelPost($this, $borrower, $byBorrower)->assertRedirect();
    expect(loanNotices(LoanNotifier::TYPE_CANCELLED))->toHaveCount(1);
    expect(Notification::where('user_id', $scientist->id)->count())->toBe(0);
});

// --- nothing before the decision is saved; a failure to notify never undoes it ------------------------------------------

test('a refused decision sends nothing', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '5']])->assertSessionHasErrors('decision');
    loanManagerPost($this, scientistUser(['lab_id' => makeLab()->id]), $loan)->assertForbidden();
    loanCancelPost($this, $scientist, $loan, ['reason' => 'x'])->assertSessionHasErrors('loan');

    expect(Notification::count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('if sending fails the decision is still saved, the user is redirected as usual and a warning is logged', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    Log::spy();
    Notification::creating(fn () => throw new RuntimeException('mail server down'));

    loanManagerPost($this, $scientist, $loan)->assertRedirect(route('loans.show', $loan))->assertSessionHasNoErrors();

    expect($loan->fresh()->status)->toBe('APPROVED');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Failed to notify about manager decision'))->once();
});

test('if sending fails on submit the request is still SUBMITTED', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);
    scientistUser(['lab_id' => $lab->id]);
    Log::spy();
    Notification::creating(fn () => throw new RuntimeException('mail server down'));

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();

    expect(Loan::firstOrFail()->status)->toBe('SUBMITTED');
    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'Failed to notify about submitted loan'))->once();
});

// --- content ------------------------------------------------------------------------------------------------------------

test('no notice or email of the loan flow carries the borrower\'s name, username, phone or student code', function () {
    [$lab, $asset] = loanBranch();
    $people = loanRoster($lab);
    $student = studentUser(['lab_id' => $lab->id, 'full_name' => 'ZZ นิสิตสมมุติ นามสมมุติ', 'username' => 'zz.nisit.secret']);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 1);
    app(LoanNotifier::class)->submitted($loan);
    loanAdvisorPost($this, $advisor, $loan)->assertRedirect();   // default: APPROVED here (CEO 2026-11-05), no manager step
    expect($loan->fresh()->status)->toBe('APPROVED');
    // A non-student request still goes through the manager's decision, so that notice is covered as well.
    $staffLoan = submittedLoan($people['staff'], $asset, 1);
    loanManagerPost($this, $people['scientist'], $staffLoan)->assertRedirect();

    $text = Notification::all()->map(fn ($n) => $n->title.' '.$n->body.' '.$n->link_url)->implode(' ');
    expect($text)->not->toBe('');
    foreach (['ZZ นิสิตสมมุติ', 'zz.nisit.secret', '0812345678', '6512345', (string) $student->email] as $leak) {
        expect($text)->not->toContain($leak);
    }
});
