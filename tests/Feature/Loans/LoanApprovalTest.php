<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanAccess;
use App\Domain\Loan\Services\LoanApprovalService;
use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 2: the two decisions on a SUBMITTED loan, through the REAL routes and the service.
 *
 *  MANAGER step: an active holder of loan.approve whose users.lab_id equals the document's lab_id. The branch is
 *  required of EVERY holder (NULL matches nothing); ADMIN holds no loan.approve, so a pure ADMIN approves nothing and
 *  ADMIN+SCIENTIST approves only in their own branch. Approving one's own request is allowed (approvals.md K) but
 *  leaves LOAN_SELF_APPROVE / LOAN_SELF_REJECT (self_approval = true). The person who typed it in for the borrower
 *  cannot approve it. Quantities: never above what was asked, never above what is available now, a reason when cut.
 *  ADVISOR step: only the advisor named on the document, active, holding loan.approve_advisor, never the borrower.
 *
 * (Written without being run: see the report to the secretary.)
 */

beforeEach(function () {
    Mail::fake();
});

if (! function_exists('assertLoanUndecided')) {
    /** No manager decision happened: status as given, no approver, no approval row, no audit row, no approved quantity. */
    function assertLoanUndecided(Loan $loan, string $status = 'SUBMITTED'): void
    {
        $fresh = $loan->fresh();
        expect($fresh->status)->toBe($status);
        expect($fresh->approved_by)->toBeNull()->and($fresh->approved_at)->toBeNull()->and($fresh->reject_reason)->toBeNull();
        expect(LoanApproval::where('loan_id', $loan->id)->where('step', 'MANAGER')->count())->toBe(0);
        expect(AuditLog::where('entity_type', Loan::class)->where('entity_id', $loan->id)
            ->whereIn('action', ['LOAN_APPROVE', 'LOAN_REJECT', 'LOAN_SELF_APPROVE', 'LOAN_SELF_REJECT'])->count())->toBe(0);
        foreach ($fresh->lines()->get() as $line) {
            expect($line->qty_approved)->toBeNull();
        }
    }
}

if (! function_exists('assertAdvisorUntouched')) {
    function assertAdvisorUntouched(Loan $loan): void
    {
        $fresh = $loan->fresh();
        expect($fresh->status)->toBe('SUBMITTED')->and($fresh->advisor_signed_at)->toBeNull();
        expect(LoanApproval::where('loan_id', $loan->id)->count())->toBe(0);
        expect(AuditLog::where('entity_type', Loan::class)->where('entity_id', $loan->id)
            ->whereIn('action', ['LOAN_ADVISOR_APPROVE', 'LOAN_ADVISOR_REJECT'])->count())->toBe(0);
    }
}

if (! function_exists('loanNonApprover')) {
    /** Somebody who must NOT be able to decide a MANAGER step of a loan in `$lab`. */
    function loanNonApprover(string $kind, Lab $lab, Lab $other): User
    {
        return match ($kind) {
            'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
            'scientist with no branch' => scientistUser(['lab_id' => null]),
            'AUDITOR of another branch' => auditorUser(['lab_id' => $other->id]),
            'AUDITOR with no branch' => auditorUser(['lab_id' => null]),
            'LAB_MANAGER of another branch' => labManagerUser(['lab_id' => $other->id]),
            'LAB_MANAGER with no branch' => labManagerUser(['lab_id' => null]),
            'ADMIN + SCIENTIST of another branch' => navUser('admin+scientist', ['lab_id' => $other->id]),
            'ADMIN + AUDITOR with no branch' => navUser('admin+auditor', ['lab_id' => null]),
            'ADMIN alone, same branch' => adminUser(['lab_id' => $lab->id]),
            'ADMIN alone, no branch' => adminUser(['lab_id' => null]),
            'STAFF of the same branch' => staffUser(['lab_id' => $lab->id]),
            'ADVISOR of the same branch' => navUser('advisor', ['lab_id' => $lab->id]),
        };
    }
}

// --- who may approve (MANAGER step) -----------------------------------------------------------------------

test('a holder of loan.approve in the loan\'s own branch approves a non-student request in one step, and it is recorded', function (string $factory) {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($staff, $asset, 3);
    $approver = $factory(['lab_id' => $lab->id]);

    loanManagerPost($this, $approver, $loan)->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->approved_by)->toBe($approver->id)->and($fresh->approved_at)->not->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty)->toBe('3.000000');
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBe('3.000000');   // blank = as requested

    $approvals = LoanApproval::where('loan_id', $loan->id)->get();
    expect($approvals)->toHaveCount(1);
    expect($approvals[0]->step)->toBe('MANAGER')->and($approvals[0]->decision)->toBe('APPROVE')->and($approvals[0]->actor_id)->toBe($approver->id);

    $audit = loanAudit('LOAN_APPROVE', $loan);
    expect($audit)->toHaveCount(1);
    expect($audit[0]->new_value['self_approval'])->toBeFalse()->and($audit[0]->new_value['approver_id'])->toBe($approver->id);
    expect($audit[0]->new_value['advisor_also_approver'])->toBeFalse()->and($audit[0]->new_value['lab_id'])->toBe($lab->id);
    expect(loanAudit('LOAN_SELF_APPROVE', $loan))->toHaveCount(0);
})->with(['scientistUser', 'auditorUser', 'labManagerUser']);

test('everyone else is refused — 403 on the page, an exception in the service — and nothing is written', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $other = makeLab();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);
    $user = loanNonApprover($kind, $lab, $other);
    $service = app(LoanApprovalService::class);

    loanManagerPost($this, $user, $loan)->assertForbidden();
    loanManagerPost($this, $user, $loan, ['decision' => 'REJECT', 'reason' => 'ไม่อนุมัติทดสอบ'])->assertForbidden();

    expect(fn () => $service->managerDecide($loan, $user->fresh(), 'APPROVE'))
        ->toThrow(LoanDomainException::class, __('loans.error.approver_not_eligible'));
    expect(fn () => $service->managerDecide($loan, $user->fresh(), 'REJECT', 'ไม่อนุมัติทดสอบ'))
        ->toThrow(LoanDomainException::class, __('loans.error.approver_not_eligible'));

    assertLoanUndecided($loan);
})->with([
    'scientist of another branch', 'scientist with no branch',
    'AUDITOR of another branch', 'AUDITOR with no branch',
    'LAB_MANAGER of another branch', 'LAB_MANAGER with no branch',
    'ADMIN + SCIENTIST of another branch', 'ADMIN + AUDITOR with no branch',
    'ADMIN alone, same branch', 'ADMIN alone, no branch',
    'STAFF of the same branch', 'ADVISOR of the same branch',
]);

test('an inactive holder of loan.approve is refused by the service even in the right branch', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);
    $inactive = scientistUser(['lab_id' => $lab->id, 'is_active' => false]);

    expect(fn () => app(LoanApprovalService::class)->managerDecide($loan, $inactive->fresh(), 'APPROVE'))
        ->toThrow(LoanDomainException::class, __('loans.error.approver_not_eligible'));
    assertLoanUndecided($loan);
});

test('a person who is ADMIN and SCIENTIST approves only in their own branch, like anyone else', function () {
    [$labA, $assetA] = loanBranch();
    [$labB, $assetB] = loanBranch();
    $both = navUser('admin+scientist', ['lab_id' => $labA->id]);
    $loanA = submittedLoan(staffUser(['lab_id' => $labA->id]), $assetA);
    $loanB = submittedLoan(staffUser(['lab_id' => $labB->id]), $assetB);

    loanManagerPost($this, $both, $loanB)->assertForbidden();
    assertLoanUndecided($loanB);

    loanManagerPost($this, $both, $loanA)->assertRedirect();
    expect($loanA->fresh()->status)->toBe('APPROVED')->and($loanA->fresh()->approved_by)->toBe($both->id);
});

test('a pure ADMIN reads every branch\'s loans but decides none (approvals.md K)', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);
    $admin = adminUser(['lab_id' => null]);

    $this->actingAs($admin)->get(route('loans.show', $loan))->assertOk();
    loanManagerPost($this, $admin, $loan)->assertForbidden();
    loanManagerPost($this, $admin, $loan, ['decision' => 'REJECT', 'reason' => 'ทดสอบ ADMIN'])->assertForbidden();
    assertLoanUndecided($loan);
});

test('LoanAccess: the branch is required on both sides, NULL never matches NULL, and the account must be active', function () {
    $access = app(LoanAccess::class);
    $lab = makeLab();
    $noBranch = scientistUser(['lab_id' => null])->fresh();
    $scientist = scientistUser(['lab_id' => $lab->id])->fresh();
    $inactive = scientistUser(['lab_id' => $lab->id, 'is_active' => false])->fresh();

    expect($access->isApproverFor($noBranch, null))->toBeFalse();
    expect($access->isApproverFor($noBranch, $lab->id))->toBeFalse();
    expect($access->isApproverFor($scientist, null))->toBeFalse();
    expect($access->isApproverFor($scientist, makeLab()->id))->toBeFalse();
    expect($access->isApproverFor($inactive, $lab->id))->toBeFalse();
    expect($access->isApproverFor($scientist, $lab->id))->toBeTrue();

    expect($access->sameBranch($noBranch, null))->toBeFalse();
    expect($access->sameBranch($scientist, null))->toBeFalse();
    expect($access->sameBranch($scientist, $lab->id))->toBeTrue();
});

// --- approving one's own request, and the person who typed it in --------------------------------------------

test('a branch approver may approve their OWN request, and the audit row says so (LOAN_SELF_APPROVE, self_approval = true)', function (string $factory) {
    [$lab, $asset] = loanBranch();
    $me = $factory(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $loan = submittedLoan($me, $asset, 2);

    loanManagerPost($this, $me, $loan)->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->approved_by)->toBe($me->id);
    $rows = loanAudit('LOAN_SELF_APPROVE', $loan);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->new_value['self_approval'])->toBeTrue()->and($rows[0]->new_value['approver_id'])->toBe($me->id)->and($rows[0]->new_value['borrower_id'])->toBe($me->id);
    expect($rows[0]->message)->toBe('borrower = approver');
    expect(loanAudit('LOAN_APPROVE', $loan))->toHaveCount(0);
    expect(LoanApproval::where('loan_id', $loan->id)->where('step', 'MANAGER')->where('actor_id', $me->id)->count())->toBe(1);
})->with(['scientistUser', 'auditorUser', 'labManagerUser']);

test('rejecting one\'s own request is allowed with a reason and recorded as LOAN_SELF_REJECT', function () {
    [$lab, $asset] = loanBranch();
    $me = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $loan = submittedLoan($me, $asset, 2);

    loanManagerPost($this, $me, $loan, ['decision' => 'REJECT', 'reason' => 'ยกเลิกความต้องการเอง'])->assertRedirect();

    expect($loan->fresh()->status)->toBe('REJECTED');
    $rows = loanAudit('LOAN_SELF_REJECT', $loan);
    expect($rows)->toHaveCount(1);
    expect($rows[0]->new_value['self_approval'])->toBeTrue();
    expect(loanAudit('LOAN_REJECT', $loan))->toHaveCount(0);
});

test('a borrower who is ADMIN needs a scientist or warehouse manager of the branch to approve (ADMIN cannot approve even their own)', function () {
    [$lab, $asset] = loanBranch();
    $admin = adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $loan = submittedLoan($admin, $asset, 1);

    loanManagerPost($this, $admin, $loan)->assertForbidden();
    assertLoanUndecided($loan);

    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertRedirect();
    expect($loan->fresh()->status)->toBe('APPROVED');
});

test('the person who typed the request in for the borrower cannot approve it (BR-L04); another approver can', function () {
    [$lab, $asset] = loanBranch();
    $counter = scientistUser(['lab_id' => $lab->id]);
    $other = scientistUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($borrower, $asset, 2, $counter);
    expect($loan->created_by)->toBe($counter->id)->and($loan->borrower_id)->toBe($borrower->id);

    loanManagerPost($this, $counter, $loan)->assertForbidden();
    expect(fn () => app(LoanApprovalService::class)->managerDecide($loan, $counter->fresh(), 'APPROVE'))
        ->toThrow(LoanDomainException::class, __('loans.error.creator_cannot_approve'));
    assertLoanUndecided($loan);

    // The borrower holds no loan.approve; the colleague does.
    loanManagerPost($this, $borrower, $loan)->assertForbidden();
    loanManagerPost($this, $other, $loan)->assertRedirect();
    expect($loan->fresh()->status)->toBe('APPROVED')->and($loan->fresh()->approved_by)->toBe($other->id);
});

test('BR-L03 (changed): the advisor may also be the manager approver; nothing forbids it and the audit row records advisor_also_approver', function () {
    // Needs the two-step path (switch back to true; the default since 2026-11-05 is false), same as a legacy row.
    config(['loans.student_needs_manager_step' => true]);
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $advisor->update(['lab_id' => $lab->id]);
    $advisor->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());
    $loan = submittedLoan($student, $asset, 2);
    $advisor = $advisor->fresh();

    loanAdvisorPost($this, $advisor, $loan)->assertRedirect(route('loans.show', $loan));
    expect($loan->fresh()->status)->toBe('ADVISOR_APPROVED');
    loanManagerPost($this, $advisor, $loan)->assertRedirect(route('loans.show', $loan));

    expect($loan->fresh()->status)->toBe('APPROVED');
    $audit = loanAudit('LOAN_APPROVE', $loan);
    expect($audit)->toHaveCount(1);
    expect($audit[0]->new_value['advisor_also_approver'])->toBeTrue()->and($audit[0]->new_value['self_approval'])->toBeFalse();
    expect(LoanApproval::where('loan_id', $loan->id)->orderBy('id')->pluck('step')->all())->toBe(['ADVISOR', 'MANAGER']);
});

// --- order of the steps ---------------------------------------------------------------------------------------

test('a decided loan cannot be decided again: the page answers 403 and the service refuses with the transition message', function () {
    [$lab, $asset] = loanBranch();
    $first = scientistUser(['lab_id' => $lab->id]);
    $second = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);

    loanManagerPost($this, $first, $loan)->assertRedirect();
    loanManagerPost($this, $second, $loan, ['decision' => 'REJECT', 'reason' => 'ช้าไปแล้ว'])->assertForbidden();
    expect(fn () => app(LoanApprovalService::class)->managerDecide($loan->fresh(), $second->fresh(), 'REJECT', 'ช้าไปแล้ว'))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect($loan->fresh()->status)->toBe('APPROVED')->and($loan->fresh()->reject_reason)->toBeNull();
    expect(LoanApproval::where('loan_id', $loan->id)->where('step', 'MANAGER')->count())->toBe(1);
});

test('BR-L03: a STUDENT\'s request waits for the advisor first — the page says 403, the service says advisor first, and the signature column is checked too', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(studentUser(['lab_id' => $lab->id]), $asset);
    $service = app(LoanApprovalService::class);

    loanManagerPost($this, $scientist, $loan)->assertForbidden();
    expect(fn () => $service->managerDecide($loan, $scientist, 'APPROVE'))->toThrow(LoanDomainException::class, __('loans.error.advisor_first'));
    assertLoanUndecided($loan);

    // Data-integrity guard: the status says ADVISOR_APPROVED but `advisor_signed_at` was never set.
    $loan = forceLoanStatus($loan, 'ADVISOR_APPROVED');
    expect($loan->advisor_signed_at)->toBeNull();
    expect(fn () => $service->managerDecide($loan, $scientist->fresh(), 'APPROVE'))->toThrow(LoanDomainException::class, __('loans.error.advisor_first'));
    assertLoanUndecided($loan, 'ADVISOR_APPROVED');
});

test('two approvers pressing at once: the second holds a stale copy (still SUBMITTED) but the service re-reads under lock and refuses, the first one wins', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);
    $firstView = Loan::findOrFail($loan->id);    // both people loaded the page while it was SUBMITTED
    $secondView = Loan::findOrFail($loan->id);
    $first = scientistUser(['lab_id' => $lab->id]);
    $second = scientistUser(['lab_id' => $lab->id]);
    $service = app(LoanApprovalService::class);

    $service->managerDecide($firstView, $first->fresh(), 'APPROVE');
    expect($secondView->status)->toBe('SUBMITTED');   // the stale copy really is stale

    expect(fn () => $service->managerDecide($secondView, $second->fresh(), 'REJECT', 'กดพร้อมกัน'))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->approved_by)->toBe($first->id)->and($fresh->reject_reason)->toBeNull();
    expect(LoanApproval::where('loan_id', $loan->id)->where('step', 'MANAGER')->count())->toBe(1);
    // The loser left no audit row either.
    expect(AuditLog::where('entity_id', $loan->id)->where('entity_type', Loan::class)->where('user_id', $second->id)->count())->toBe(0);
});

test('two advisor decisions at once: the second stale copy is refused, one ADVISOR row, one audit row', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset);
    $firstView = Loan::findOrFail($loan->id);
    $secondView = Loan::findOrFail($loan->id);
    $service = app(LoanApprovalService::class);

    $service->advisorDecide($firstView, $advisor, 'APPROVE');
    expect(fn () => $service->advisorDecide($secondView, $advisor, 'REJECT', 'ซ้ำ'))->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect($loan->fresh()->status)->toBe('APPROVED');   // default switch false: the advisor's approval is the last one
    expect(LoanApproval::where('loan_id', $loan->id)->where('step', 'ADVISOR')->count())->toBe(1);
    expect(loanAudit('LOAN_ADVISOR_APPROVE', $loan))->toHaveCount(1);
    expect(loanAudit('LOAN_ADVISOR_REJECT', $loan))->toHaveCount(0);
});

// --- reject ---------------------------------------------------------------------------------------------------

test('rejecting needs a reason; with one the loan is REJECTED, nothing is approved, and it is recorded', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'REJECT'])->assertSessionHasErrors('reason');
    loanManagerPost($this, $scientist, $loan, ['decision' => 'REJECT', 'reason' => '   '])->assertSessionHasErrors('reason');
    assertLoanUndecided($loan);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'REJECT', 'reason' => 'ของไม่ว่างในช่วงนี้'])->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('REJECTED')->and($fresh->reject_reason)->toBe('ของไม่ว่างในช่วงนี้');
    expect($fresh->approved_by)->toBeNull()->and($fresh->approved_at)->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBeNull();
    $row = LoanApproval::where('loan_id', $loan->id)->firstOrFail();
    expect($row->decision)->toBe('REJECT')->and($row->reason)->toBe('ของไม่ว่างในช่วงนี้')->and($row->actor_id)->toBe($scientist->id);
    expect(loanAudit('LOAN_REJECT', $loan))->toHaveCount(1);

    // Terminal: nobody decides it again.
    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertForbidden();
});

test('a decision other than APPROVE / REJECT is a validation error and changes nothing', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset);

    foreach ([[], ['decision' => 'approve'], ['decision' => 'MAYBE'], ['decision' => 'APPROVED']] as $payload) {
        loanManagerPost($this, $scientist, $loan, $payload)->assertSessionHasErrors('decision');
    }
    assertLoanUndecided($loan);
});

// --- quantities (BR-L02, FR-LN-13) ---------------------------------------------------------------------------

test('approving more than was asked for is refused and nothing is written', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '4']])
        ->assertSessionHasErrors(['decision' => __('loans.error.approve_more_than_asked')]);

    assertLoanUndecided($loan);
});

test('approving more than is available NOW is refused; exactly what is available (with a reason, since it is less than asked) passes', function () {
    [$lab, $asset] = loanBranch(10);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);
    $name = (string) $asset->load('item')->item?->name_th;

    // Somebody else's loan went out after the request: 9 of 10 are on loan, 1 left.
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 9]);

    loanManagerPost($this, $scientist, $loan)
        ->assertSessionHasErrors(['decision' => __('loans.error.not_enough_available', ['item' => $name])]);
    assertLoanUndecided($loan);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '1'], 'reason' => 'เหลือเพียง 1 ชิ้น'])->assertRedirect();
    $line = $loan->fresh()->lines()->firstOrFail();
    expect($line->qty)->toBe('3.000000')->and($line->qty_approved)->toBe('1.000000');
});

test('approving fewer than asked needs a reason; the request quantity is never overwritten', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '2']])
        ->assertSessionHasErrors(['decision' => __('loans.error.reduce_reason_required')]);
    assertLoanUndecided($loan);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '2'], 'reason' => 'ลดตามที่มีให้'])->assertRedirect();

    $line = $loan->fresh()->lines()->firstOrFail();
    expect($line->qty)->toBe('3.000000')->and($line->qty_approved)->toBe('2.000000');
    expect(LoanApproval::where('loan_id', $loan->id)->firstOrFail()->reason)->toBe('ลดตามที่มีให้');
});

test('approved quantities must be whole numbers of at least 1', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);

    foreach (['abc', '-1', '1.5', '2e1'] as $bad) {
        loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => $bad]])->assertSessionHasErrors('qty_approved.1');
    }
    // "0" passes the format check and is refused by the service.
    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '0'], 'reason' => 'ศูนย์'])
        ->assertSessionHasErrors(['decision' => __('loans.error.qty_approved_invalid')]);

    assertLoanUndecided($loan);
});

test('all lines or none: one refused line (cut without a reason) leaves every line untouched; then the whole decision goes through', function () {
    [$lab, $asset1] = loanBranch(10);
    $asset2 = loanExtraAsset($lab, 5);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), [$asset1, $asset2], 3);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '2']])->assertSessionHasErrors('decision');
    assertLoanUndecided($loan);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '2'], 'reason' => 'ลดบรรทัดแรก'])->assertRedirect();
    $lines = $loan->fresh()->lines()->get();
    expect($lines)->toHaveCount(2);
    expect($lines[0]->qty_approved)->toBe('2.000000')->and($lines[1]->qty_approved)->toBe('3.000000');
});

test('a quantity keyed to a line number that does not exist is ignored, and deciding one loan never touches another loan\'s lines', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loanA = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);
    $loanB = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 2);

    loanManagerPost($this, $scientist, $loanA, ['decision' => 'APPROVE', 'qty_approved' => [1 => '3', 99 => '5']])->assertRedirect();

    expect($loanA->fresh()->lines()->firstOrFail()->qty_approved)->toBe('3.000000');
    assertLoanUndecided($loanB);
});

test('a request whose return date has passed cannot be approved (BR-L06)', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1, null, loanDates(2));

    $this->travel(3)->days();

    loanManagerPost($this, $scientist, $loan)->assertSessionHasErrors(['decision' => __('loans.error.due_passed')]);
    assertLoanUndecided($loan);
});

test('an asset retired between the request and the approval makes the decision fail', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);

    loanManagerPost($this, $scientist, $loan)->assertSessionHasErrors(['decision' => __('loans.error.asset_not_lendable')]);
    assertLoanUndecided($loan);
});

test('a refused decision rolls back completely (no approval row, no audit row)', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 3);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'APPROVE', 'qty_approved' => [1 => '9']])->assertSessionHasErrors('decision');

    assertLoanUndecided($loan);
    expect(LoanApproval::where('loan_id', $loan->id)->count())->toBe(0);
});

// --- the ADVISOR step (in the system) ---------------------------------------------------------------------------

test('with the two-step switch ON (config true, the pre-2026-11-05 path): the advisor gives ADVISOR_APPROVED, nothing approved yet; then a branch approver finishes it', function () {
    config(['loans.student_needs_manager_step' => true]);
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 2);
    expect($loan->status)->toBe('SUBMITTED')->and($loan->borrower_status)->toBe('STUDENT')->and($loan->advisor_id)->toBe($advisor->id);

    loanAdvisorPost($this, $advisor, $loan)->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('ADVISOR_APPROVED')->and($fresh->advisor_signed_at)->not->toBeNull();
    expect($fresh->approved_at)->toBeNull()->and($fresh->approved_by)->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBeNull();
    $row = LoanApproval::where('loan_id', $loan->id)->firstOrFail();
    expect($row->step)->toBe('ADVISOR')->and($row->decision)->toBe('APPROVE')->and($row->actor_id)->toBe($advisor->id);
    expect(loanAudit('LOAN_ADVISOR_APPROVE', $loan))->toHaveCount(1);

    $manager = scientistUser(['lab_id' => $lab->id]);
    loanManagerPost($this, $manager, $loan)->assertRedirect();
    expect($loan->fresh()->status)->toBe('APPROVED')->and($loan->fresh()->lines()->firstOrFail()->qty_approved)->toBe('2.000000');
    expect(loanAudit('LOAN_APPROVE', $loan)[0]->new_value['advisor_also_approver'])->toBeFalse();
});

test('the advisor rejecting needs a reason and ends the request; the manager can no longer decide it', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 2);

    loanAdvisorPost($this, $advisor, $loan, ['decision' => 'REJECT'])->assertSessionHasErrors('reason');
    assertAdvisorUntouched($loan);

    loanAdvisorPost($this, $advisor, $loan, ['decision' => 'REJECT', 'reason' => 'ยังไม่ถึงขั้นตอนการทดลอง'])->assertRedirect();
    expect($loan->fresh()->status)->toBe('REJECTED');
    expect(loanAudit('LOAN_ADVISOR_REJECT', $loan))->toHaveCount(1);

    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertForbidden();
});

if (! function_exists('loanBadAdvisor')) {
    /** Makes the advisor step fail in one specific way; returns the person who tries to act. */
    function loanBadAdvisor(string $cause, Loan $loan, User $student, Lab $lab): User
    {
        $advisor = User::findOrFail($loan->advisor_id);

        return match ($cause) {
            'another advisor, not the one named' => navUser('advisor'),
            'deactivated' => tap($advisor)->update(['is_active' => false])->fresh(),
            'permission revoked' => (function () use ($advisor) {
                $advisor->roles()->detach(Role::where('code', 'ADVISOR')->firstOrFail());

                return $advisor->fresh();
            })(),
            'the borrower, named as their own advisor' => (function () use ($student, $loan) {
                $student->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());
                DB::table('loans')->where('id', $loan->id)->update(['advisor_id' => $student->id]);

                return $student->fresh();
            })(),
            'ADMIN' => adminUser(['lab_id' => $lab->id]),
            'a scientist of the branch' => scientistUser(['lab_id' => $lab->id]),
            'a warehouse manager of the branch' => auditorUser(['lab_id' => $lab->id]),
        };
    }
}

test('an ineligible person cannot take the advisor step, in the system or in the service; the document is untouched and the message names no cause', function (string $cause) {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($student, $asset, 2);
    $actor = loanBadAdvisor($cause, $loan, $student, $lab);
    $loan = $loan->fresh();

    // Refused by the policy (403) or by the service (back with an error): both leave nothing behind.
    loanAdvisorPost($this, $actor, $loan);
    loanAdvisorPost($this, $actor, $loan, ['decision' => 'REJECT', 'reason' => 'ไม่เหมาะสม']);
    assertAdvisorUntouched($loan);

    foreach (['APPROVE', 'REJECT'] as $decision) {
        expect(fn () => app(LoanApprovalService::class)->advisorDecide($loan, $actor, $decision, 'ไม่เหมาะสม'))
            ->toThrow(LoanDomainException::class, __('loans.error.advisor_not_eligible'));
    }
    assertAdvisorUntouched($loan);
})->with([
    'another advisor, not the one named', 'deactivated', 'permission revoked',
    'the borrower, named as their own advisor', 'ADMIN', 'a scientist of the branch', 'a warehouse manager of the branch',
]);

test('the refusal text is the same for every cause and names none of them', function () {
    $message = __('loans.error.advisor_not_eligible');

    foreach (['is_active', 'deactivated', 'revoked', 'ปิดใช้งาน', 'ถอดสิทธิ์', 'ตัวเอง'] as $leak) {
        expect($message)->not->toContain($leak);
    }
});

test('a non-student\'s request has no advisor step at all: even an ADVISOR gets 403 and the service refuses', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $advisor = navUser('advisor', ['lab_id' => $lab->id]);

    expect($loan->advisor_id)->toBeNull();
    loanAdvisorPost($this, $advisor, $loan)->assertForbidden();
    expect(fn () => app(LoanApprovalService::class)->advisorDecide($loan, $advisor, 'APPROVE'))->toThrow(LoanDomainException::class);
    assertLoanUndecided($loan);
    assertAdvisorUntouched($loan);
});

test('BR-L14: a student with no advisor, or whose advisor is themselves, cannot create the request at all', function () {
    [$lab, $asset] = loanBranch();

    $noAdvisor = studentUser(['lab_id' => $lab->id, 'advisor_id' => null]);
    expect(fn () => draftLoan($noAdvisor, $asset))->toThrow(LoanDomainException::class, __('loans.error.advisor_missing'));

    $self = studentUser(['lab_id' => $lab->id, 'advisor_id' => null]);
    $self->update(['advisor_id' => $self->id]);
    expect(fn () => draftLoan($self, $asset))->toThrow(LoanDomainException::class, __('loans.error.advisor_is_borrower'));

    expect(Loan::count())->toBe(0);
});

test('BR-L14: a borrower whose person_type is NULL is treated as a STUDENT — no advisor, no request; with one, it waits for the advisor', function () {
    [$lab, $asset] = loanBranch();
    $user = staffUser(['lab_id' => $lab->id, 'person_type' => null]);

    expect(fn () => draftLoan($user, $asset))->toThrow(LoanDomainException::class, __('loans.error.advisor_missing'));

    $advisor = navUser('advisor');
    $user->update(['advisor_id' => $advisor->id]);
    $loan = submittedLoan($user, $asset, 1);

    expect($loan->borrower_status)->toBe('STUDENT')->and($loan->status)->toBe('SUBMITTED')->and($loan->advisor_id)->toBe($advisor->id);
    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertForbidden();   // waits for the advisor
});

// --- CEO 2026-11-05 (approvals.md): the advisor's approval of a student's loan is the last one (default switch = false) ---

test('CEO 2026-11-05: the advisor\'s approval makes a student loan APPROVED at once, with approved quantity = requested, approved_at and advisor_signed_at set', function () {
    expect(config('loans.student_needs_manager_step'))->toBeFalse();   // the shipped default, not set by this test
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 2);

    loanAdvisorPost($this, $advisor, $loan)->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->advisor_signed_at)->not->toBeNull()->and($fresh->approved_at)->not->toBeNull();
    expect($fresh->approved_by)->toBeNull();   // nobody of the branch decided; the record shows the advisor's step only
    $line = $fresh->lines()->firstOrFail();
    expect($line->qty_approved)->toBe('2.000000')->and($line->qty_approved)->toBe($line->qty);
    expect(LoanApproval::where('loan_id', $loan->id)->pluck('step')->all())->toBe(['ADVISOR']);
    expect(loanAudit('LOAN_ADVISOR_APPROVE', $loan))->toHaveCount(1);
    expect(loanAudit('LOAN_ADVISOR_APPROVE', $loan)[0]->new_value['status'])->toBe('APPROVED');
    expect(loanAudit('LOAN_APPROVE', $loan))->toHaveCount(0);   // no manager decision was made

    // Done: a branch approver has nothing left to decide.
    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertForbidden();
    expect(fn () => app(LoanApprovalService::class)->managerDecide($loan->fresh(), scientistUser(['lab_id' => $lab->id]), 'APPROVE'))
        ->toThrow(LoanDomainException::class);
});

test('the advisor\'s direct approval goes through the same service in the signed-link path too (same state, same quantities)', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 3);

    $result = app(LoanApprovalService::class)->advisorDecide($loan, $advisor, 'APPROVE', null, '127.0.0.1');

    expect($result->status)->toBe('APPROVED');
    expect($loan->fresh()->lines()->firstOrFail()->qty_approved)->toBe('3.000000');
});

test('advisor approval that fails the availability checks is refused with the advisor wording and rolls back completely', function (string $cause) {
    [$lab, $asset] = loanBranch(10);
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $dates = $cause === 'due date passed' ? loanDates(2) : [];
    $loan = submittedLoan($student, $asset, 3, null, $dates);
    $name = (string) $asset->load('item')->item?->name_th;

    $expected = match ($cause) {
        'asset not ACTIVE' => (function () use ($asset) {
            DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);

            return __('loans.error.asset_not_lendable_advisor');
        })(),
        'more than available' => (function () use ($asset, $name) {
            DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 9]);   // 1 of 10 left, 3 asked

            return __('loans.error.not_enough_available_advisor', ['item' => $name]);
        })(),
        'due date passed' => (function () {
            test()->travel(3)->days();

            return __('loans.error.due_passed_advisor');
        })(),
    };

    expect(fn () => app(LoanApprovalService::class)->advisorDecide($loan, $advisor, 'APPROVE'))
        ->toThrow(LoanDomainException::class, $expected);

    // Nothing was written: still SUBMITTED, no signature, no approved quantity, no approval row, no audit row.
    assertAdvisorUntouched($loan);
    $fresh = $loan->fresh();
    expect($fresh->approved_at)->toBeNull()->and($fresh->approved_by)->toBeNull()->and($fresh->reject_reason)->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBeNull();
    // (the LOAN_CREATE / LOAN_SUBMIT rows from the set-up stay; the refused decision adds nothing)
    expect(AuditLog::where('entity_type', Loan::class)->where('entity_id', $loan->id)->whereNotIn('action', ['LOAN_CREATE', 'LOAN_SUBMIT'])->count())->toBe(0);
})->with(['asset not ACTIVE', 'more than available', 'due date passed']);

test('the advisor-wording messages differ from the manager ones (they tell the advisor to ask the student, not to cut a quantity)', function () {
    foreach (['due_passed', 'asset_not_lendable', 'not_enough_available'] as $key) {
        expect(__('loans.error.'.$key.'_advisor', ['item' => 'X']))->not->toBe(__('loans.error.'.$key, ['item' => 'X']));
        expect(__('loans.error.'.$key.'_advisor', ['item' => 'X']))->not->toBe('loans.error.'.$key.'_advisor');   // the key exists
    }
});

test('the advisor rejecting a student loan: REJECTED, advisor_signed_at stays NULL (a rejection is not a signature), the reason is kept, nothing approved', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 2);

    loanAdvisorPost($this, $advisor, $loan, ['decision' => 'REJECT', 'reason' => 'ยังไม่ถึงขั้นตอนการทดลอง'])->assertRedirect();

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('REJECTED')->and($fresh->advisor_signed_at)->toBeNull();
    expect($fresh->reject_reason)->toBe('ยังไม่ถึงขั้นตอนการทดลอง');
    expect($fresh->approved_at)->toBeNull()->and($fresh->approved_by)->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBeNull();
    $row = LoanApproval::where('loan_id', $loan->id)->firstOrFail();
    expect($row->step)->toBe('ADVISOR')->and($row->decision)->toBe('REJECT')->and($row->reason)->toBe('ยังไม่ถึงขั้นตอนการทดลอง');
    expect(loanAudit('LOAN_ADVISOR_REJECT', $loan))->toHaveCount(1);
});

test('a rejection does not run the availability checks: it still goes through when the asset is retired or the due date has passed', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 1, null, loanDates(2));
    DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);
    $this->travel(3)->days();

    app(LoanApprovalService::class)->advisorDecide($loan, $advisor, 'REJECT', 'ไม่เหมาะสม');

    expect($loan->fresh()->status)->toBe('REJECTED');
});

test('an old ADVISOR_APPROVED student loan (advisor signed before the decision) can still be decided by the branch approver, whatever the switch says', function (bool $switch) {
    config(['loans.student_needs_manager_step' => $switch]);
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(studentUser(['lab_id' => $lab->id]), $asset, 2);
    $loan = forceLoanStatus($loan, 'ADVISOR_APPROVED', ['advisor_signed_at' => now()]);

    loanManagerPost($this, $scientist, $loan)->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->approved_by)->toBe($scientist->id)->and($fresh->approved_at)->not->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBe('2.000000');
    expect(loanAudit('LOAN_APPROVE', $loan))->toHaveCount(1);
})->with([false, true]);

test('an old ADVISOR_APPROVED student loan can also be rejected by the branch approver (with a reason)', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(studentUser(['lab_id' => $lab->id]), $asset, 2);
    $loan = forceLoanStatus($loan, 'ADVISOR_APPROVED', ['advisor_signed_at' => now()]);

    loanManagerPost($this, $scientist, $loan, ['decision' => 'REJECT', 'reason' => 'ของไม่พอ'])->assertRedirect();

    expect($loan->fresh()->status)->toBe('REJECTED')->and($loan->fresh()->reject_reason)->toBe('ของไม่พอ');
});

test('switch back to true (restoring the old BR-L03 path) leaves the advisor step as ADVISOR_APPROVED with nothing approved yet and no availability check', function () {
    config(['loans.student_needs_manager_step' => true]);
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 2);
    DB::table('loan_assets')->where('id', $asset->id)->update(['status' => 'RETIRED']);   // would fail the direct path

    app(LoanApprovalService::class)->advisorDecide($loan, $advisor, 'APPROVE');

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('ADVISOR_APPROVED')->and($fresh->approved_at)->toBeNull();
    expect($fresh->lines()->firstOrFail()->qty_approved)->toBeNull();
});
