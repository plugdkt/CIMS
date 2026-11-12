<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanAccess;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Domain\Navigation\MenuRegistry;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanMovement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 3 (WP-3.6): WHO may stand at the counter and what each role reaches by URL.
 *
 *  Handler (hand-out / return) = an ACTIVE holder of `loan.handout` that comes from a role OTHER than ADMIN, whose
 *  users.lab_id equals the document's lab_id. No branch = no; another branch = no; ADMIN alone = no (even in the right
 *  branch); ADMIN + SCIENTIST of the branch = yes. Checked on the page (LoanPolicy), in LoanCirculationService and
 *  again in LoanLedgerService.
 *
 *  The SELF rule (borrower = the person at the counter) has its own section near the end, on purpose: it follows the
 *  CEO's "same rule as requisitions" (allowed, but audited as LOAN_SELF_*), which replaced the spec's BR-L04 "never the
 *  borrower" and still waits for the CEO's confirmation. If the answer becomes "forbid", only that section flips.
 *
 *  Also here: IDOR on the document / slip, the public /verify page, no prices on any counter page, the pages
 *  themselves, the menu tab and the badges.
 */

beforeEach(function () {
    Mail::fake();
});

/** Somebody who must NOT be able to hand out / take back a loan of `$lab`. */
function loanNonHandler(string $kind, Lab $lab, Lab $other): User
{
    return match ($kind) {
        'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'scientist with no branch' => scientistUser(['lab_id' => null]),
        'AUDITOR of another branch' => auditorUser(['lab_id' => $other->id]),
        'LAB_MANAGER of another branch' => labManagerUser(['lab_id' => $other->id]),
        'LAB_MANAGER with no branch' => labManagerUser(['lab_id' => null]),
        'ADMIN + SCIENTIST of another branch' => navUser('admin+scientist', ['lab_id' => $other->id]),
        'ADMIN + AUDITOR with no branch' => navUser('admin+auditor', ['lab_id' => null]),
        'ADMIN alone, same branch' => adminUser(['lab_id' => $lab->id]),
        'ADMIN alone, no branch' => adminUser(['lab_id' => null]),
        'STAFF of the same branch' => staffUser(['lab_id' => $lab->id]),
        'ADVISOR of the same branch' => navUser('advisor', ['lab_id' => $lab->id]),
        'STUDENT of the same branch' => studentUser(['lab_id' => $lab->id]),
    };
}

dataset('non handlers', [
    'scientist of another branch', 'scientist with no branch',
    'AUDITOR of another branch', 'LAB_MANAGER of another branch', 'LAB_MANAGER with no branch',
    'ADMIN + SCIENTIST of another branch', 'ADMIN + AUDITOR with no branch',
    'ADMIN alone, same branch', 'ADMIN alone, no branch',
    'STAFF of the same branch', 'ADVISOR of the same branch', 'STUDENT of the same branch',
]);

// --- who may hand out / take back ------------------------------------------------------------------------------------

test('a scientist or warehouse manager of the loan\'s own branch hands out and takes back; so does ADMIN + SCIENTIST of that branch', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $loan = approvedLoan(staffUser(['lab_id' => $lab->id]), $asset, 2);
    $handler = navUser($kind, ['lab_id' => $lab->id]);

    $this->actingAs($handler)->get(route('loans.handout.form', $loan))->assertOk();
    loanHandoutPost($this, $handler, $loan)->assertRedirect(route('loans.show', $loan));
    expect($loan->fresh()->status)->toBe('ON_LOAN');

    $this->actingAs($handler)->get(route('loans.return.form', $loan))->assertOk();
    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '2', 'condition' => 'GOOD']])->assertRedirect(route('loans.show', $loan));
    expect($loan->fresh()->status)->toBe('CLOSED');

    expect(LoanMovement::where('loan_id', $loan->id)->pluck('created_by')->unique()->all())->toBe([$handler->id]);
    expect(loanAudit('LOAN_HANDOUT', $loan))->toHaveCount(1)->and(loanAudit('LOAN_RETURN', $loan))->toHaveCount(1);
})->with(['scientist', 'auditor', 'lab_manager', 'admin+scientist', 'admin+auditor', 'staff+scientist']);

test('everyone else is refused the hand-out — 403 on the form and on the POST, an exception in the service and in the ledger — and nothing is written', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $loan = approvedLoan(staffUser(['lab_id' => $lab->id]), $asset, 2);
    $user = loanNonHandler($kind, $lab, makeLab());
    $before = loanCounterState($loan);

    $this->actingAs($user)->get(route('loans.handout.form', $loan))->assertForbidden();
    loanHandoutPost($this, $user, $loan)->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->handOut($user->fresh(), $loan, loanConditions($loan)))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));
    expect(fn () => loanService()->handOut($user->fresh(), $asset->fresh(), $loan, $loan->lines()->firstOrFail(), '2.000000', 'GOOD', null))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));

    expect(loanCounterState($loan))->toBe($before);
    expect($loan->fresh()->status)->toBe('APPROVED');
})->with('non handlers');

test('everyone else is refused the return — 403 on the form and on the POST, an exception in the service and in the ledger — and nothing is written', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), $asset, 2);
    $user = loanNonHandler($kind, $lab, makeLab());
    $before = loanCounterState($loan);
    $rows = [1 => ['qty' => '1', 'condition' => 'GOOD']];

    $this->actingAs($user)->get(route('loans.return.form', $loan))->assertForbidden();
    loanReturnPost($this, $user, $loan, $rows)->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($user->fresh(), $loan, $rows))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));
    expect(fn () => loanService()->takeBack($user->fresh(), $asset->fresh(), $loan, $loan->lines()->firstOrFail(), 'RETURN_OK', '1.000000', 'GOOD', null))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));

    expect(loanCounterState($loan))->toBe($before);
    expect($loan->fresh()->status)->toBe('ON_LOAN');
})->with('non handlers');

test('an inactive scientist of the right branch is refused by the service', function () {
    [$lab, $asset] = loanBranch();
    $loan = approvedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $inactive = scientistUser(['lab_id' => $lab->id, 'is_active' => false]);

    expect(fn () => app(LoanCirculationService::class)->handOut($inactive->fresh(), $loan, loanConditions($loan)))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));
    expect($loan->fresh()->status)->toBe('APPROVED');
});

test('LoanAccess::isHandlerFor: the permission must come from a role other than ADMIN, the branch is required on both sides', function () {
    $access = app(LoanAccess::class);
    $lab = makeLab();
    $other = makeLab();

    expect($access->isHandlerFor(scientistUser(['lab_id' => $lab->id]), $lab->id))->toBeTrue();
    expect($access->isHandlerFor(auditorUser(['lab_id' => $lab->id]), $lab->id))->toBeTrue();
    expect($access->isHandlerFor(labManagerUser(['lab_id' => $lab->id]), $lab->id))->toBeTrue();
    expect($access->isHandlerFor(navUser('admin+scientist', ['lab_id' => $lab->id]), $lab->id))->toBeTrue();

    // ADMIN holds loan.handout (to type a request in for a borrower) but is not a handler by that alone.
    $admin = adminUser(['lab_id' => $lab->id]);
    expect($admin->hasPermission('loan.handout'))->toBeTrue();
    expect($access->isHandlerFor($admin, $lab->id))->toBeFalse();

    expect($access->isHandlerFor(scientistUser(['lab_id' => $other->id]), $lab->id))->toBeFalse();
    expect($access->isHandlerFor(scientistUser(['lab_id' => null]), $lab->id))->toBeFalse();
    expect($access->isHandlerFor(scientistUser(['lab_id' => null]), null))->toBeFalse();   // NULL never matches NULL
    expect($access->isHandlerFor(scientistUser(['lab_id' => $lab->id]), null))->toBeFalse();
    expect($access->isHandlerFor(scientistUser(['lab_id' => $lab->id, 'is_active' => false]), $lab->id))->toBeFalse();
    expect($access->isHandlerFor(staffUser(['lab_id' => $lab->id]), $lab->id))->toBeFalse();
});

// --- the new routes by URL ----------------------------------------------------------------------------------------------

dataset('loan step 3 pages', function () {
    $open = 200;
    $deny = 403;

    // [student, staff, advisor, scientist, lab_manager, auditor, admin (has a branch), nocard]
    $matrix = ['loans.desk' => [$deny, $deny, $deny, $open, $open, $open, $open, $deny]];

    foreach ($matrix as $routeName => $answers) {
        foreach (['student', 'staff', 'advisor', 'scientist', 'lab_manager', 'auditor', 'admin', 'nocard'] as $i => $kind) {
            yield "{$routeName} as {$kind}" => [$routeName, $kind, $answers[$i]];
        }
    }
});

test('the counter page answers each role exactly as designed, asked directly by URL', function (string $routeName, string $kind, int $expected) {
    $this->actingAs(navUser($kind))->get(route($routeName))->assertStatus($expected);
})->with('loan step 3 pages');

test('the counter page needs a branch: a scientist, a manager or an ADMIN with none gets 403', function (string $kind) {
    $this->actingAs(navUser($kind, ['lab_id' => null]))->get(route('loans.desk'))->assertForbidden();
})->with(['scientist', 'auditor', 'lab_manager', 'admin']);

test('every step-3 route sends a guest to the login page and changes nothing', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $asset, 1);
    $out = handedOutLoan($borrower, $asset, 1);

    $this->get(route('loans.desk'))->assertRedirect(route('login'));
    $this->get(route('loans.pdf', $out))->assertRedirect(route('login'));
    $this->get(route('loans.handout.form', $approved))->assertRedirect(route('login'));
    $this->post(route('loans.handout', $approved), ['lines' => loanConditions($approved)])->assertRedirect(route('login'));
    $this->get(route('loans.return.form', $out))->assertRedirect(route('login'));
    $this->post(route('loans.return', $out), ['lines' => [1 => ['qty' => '1', 'condition' => 'GOOD']]])->assertRedirect(route('login'));
    $this->post(route('loans.renewals.store', $out), ['new_due_date' => $out->due_date->copy()->addDays(3)->format('Y-m-d')])->assertRedirect(route('login'));
    $this->post(route('loans.renewals.decide', ['loan' => $out, 'seq' => 1]), ['decision' => 'APPROVE'])->assertRedirect(route('login'));

    expect($approved->fresh()->status)->toBe('APPROVED')->and($out->fresh()->status)->toBe('ON_LOAN');
    expect($out->fresh()->due_date->toDateString())->toBe($out->due_date->toDateString());
});

test('a user who holds no loan permission at all gets 403 on every step-3 route', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $asset, 1);
    $out = handedOutLoan($borrower, $asset, 1);
    $nocard = navUser('nocard', ['lab_id' => $lab->id]);

    $this->actingAs($nocard)->get(route('loans.desk'))->assertForbidden();
    $this->actingAs($nocard)->get(route('loans.show', $out))->assertForbidden();
    $this->actingAs($nocard)->get(route('loans.pdf', $out))->assertForbidden();
    $this->actingAs($nocard)->get(route('loans.handout.form', $approved))->assertForbidden();
    loanHandoutPost($this, $nocard, $approved)->assertForbidden();
    $this->actingAs($nocard)->get(route('loans.return.form', $out))->assertForbidden();
    loanReturnPost($this, $nocard, $out, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertForbidden();
    $this->actingAs($nocard)->post(route('loans.renewals.store', $out), ['new_due_date' => $out->due_date->copy()->addDays(3)->format('Y-m-d')])->assertForbidden();
    $this->actingAs($nocard)->post(route('loans.renewals.decide', ['loan' => $out, 'seq' => 1]), ['decision' => 'APPROVE'])->assertForbidden();
});

test('the Gate::before trap does not reach step 3: a scientist of ANOTHER branch passes can(\'loan.handout\', $loan) but none of the real abilities', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $asset, 1);
    $out = handedOutLoan($borrower, $asset, 1);
    $foreign = scientistUser(['lab_id' => makeLab()->id]);

    expect($foreign->can('loan.handout', $approved))->toBeTrue();   // pins Gate::before, the reason for the naming rule

    expect($foreign->can('handOutLoan', $approved))->toBeFalse();
    foreach (['viewLoan', 'printLoan', 'receiveReturnLoan', 'renewLoan', 'decideRenewalLoan'] as $ability) {
        expect($foreign->can($ability, $out))->toBeFalse($ability);
    }
});

// --- IDOR: the document, the slip, another branch -------------------------------------------------------------------------

test('the document and its slip open for the borrower, the counter and approvers of the branch, and ADMIN; for nobody else', function () {
    [$lab, $asset] = loanBranch();
    $other = makeLab();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 1);

    $may = [
        'the borrower' => $borrower,
        'a scientist of the branch' => scientistUser(['lab_id' => $lab->id]),
        'a manager of the branch' => auditorUser(['lab_id' => $lab->id]),
        'ADMIN with no branch' => adminUser(['lab_id' => null]),
    ];
    foreach ($may as $who => $user) {
        $this->actingAs($user)->get(route('loans.show', $loan))->assertOk();
        $pdf = $this->actingAs($user)->get(route('loans.pdf', $loan))->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf', $who);
        expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-', $who);
        expect($pdf->headers->get('Content-Disposition'))->toBe('inline; filename="'.$loan->doc_no.'.pdf"', $who);
    }

    $mayNot = [
        'another borrower of the same branch' => staffUser(['lab_id' => $lab->id]),
        'a student of the same branch' => studentUser(['lab_id' => $lab->id]),
        'an advisor who is not this loan\'s advisor' => navUser('advisor', ['lab_id' => $lab->id]),
        'a scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'a manager of another branch' => labManagerUser(['lab_id' => $other->id]),
        'a scientist with no branch' => scientistUser(['lab_id' => null]),
    ];
    foreach ($mayNot as $who => $user) {
        $this->actingAs($user)->get(route('loans.show', $loan))->assertForbidden();
        $this->actingAs($user)->get(route('loans.pdf', $loan))->assertForbidden();
    }
});

test('a DRAFT has no slip, not even for its owner; an unknown ULID is 404 on every step-3 route', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $draft = draftLoan($borrower, $asset, 1);

    $this->actingAs($borrower)->get(route('loans.show', $draft))->assertOk();
    $this->actingAs($borrower)->get(route('loans.pdf', $draft))->assertForbidden();

    $ghost = (string) \Illuminate\Support\Str::ulid();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    foreach (['loans.pdf', 'loans.handout.form', 'loans.return.form'] as $name) {
        $this->actingAs($scientist)->get(route($name, $ghost))->assertNotFound();
    }
    $this->actingAs($scientist)->post(route('loans.handout', $ghost), [])->assertNotFound();
    $this->actingAs($scientist)->post(route('loans.return', $ghost), [])->assertNotFound();
    // Loans are addressed by ULID only: the auto-increment id is not a key.
    $this->actingAs($borrower)->get('/loans/'.$draft->id)->assertNotFound();
    $this->actingAs($borrower)->get('/loans/'.$draft->id.'/pdf')->assertNotFound();
});

// --- /verify/{ulid}: public, nothing personal -------------------------------------------------------------------------------

test('the public /verify page of a loan shows the document number, the pick-up date and the status, and nothing about people, branch or items', function () {
    $lab = makeLab(['name_th' => 'สาขาลับเฉพาะทดสอบ']);
    $stocker = auditorUser(['lab_id' => $lab->id]);
    $unit = registerLoanAsset($stocker, $lab, 'UNIT', 1, makeCountItem(['name_th' => 'กล้องจุลทรรศน์ลับเฉพาะ']));
    $student = studentUser(['lab_id' => $lab->id, 'full_name' => 'นิสิตผู้ยืมลับเฉพาะ']);
    $advisor = User::findOrFail($student->advisor_id);
    $advisor->update(['full_name' => 'อาจารย์ที่ปรึกษาลับเฉพาะ']);
    $handler = scientistUser(['lab_id' => $lab->id, 'full_name' => 'นักวิทย์ผู้ส่งมอบลับเฉพาะ']);

    $loan = submittedLoan($student, $unit, 1, null, ['purpose_detail' => 'รายละเอียดลับเฉพาะ', 'remark' => 'หมายเหตุลับเฉพาะ']);
    $secrets = [
        'นิสิตผู้ยืมลับเฉพาะ', 'อาจารย์ที่ปรึกษาลับเฉพาะ', 'นักวิทย์ผู้ส่งมอบลับเฉพาะ', 'สาขาลับเฉพาะทดสอบ', $lab->code,
        'กล้องจุลทรรศน์ลับเฉพาะ', (string) $unit->asset_tag, 'รายละเอียดลับเฉพาะ', 'หมายเหตุลับเฉพาะ',
        (string) $student->email, (string) $student->username, '6512345', '0812345678',
    ];

    // SUBMITTED: the plain word, never "waiting for the advisor" (that would say the borrower is a student).
    $page = $this->get('/verify/'.$loan->ulid)->assertOk();
    $page->assertSee($loan->doc_no)->assertSee($loan->loan_date->format('d/m/Y'))->assertSee(__('loans.loan_status_submitted'));
    $page->assertDontSee(__('loans.loan_status_waiting_advisor'));
    foreach ($secrets as $secret) {
        $page->assertDontSee($secret);
    }

    app(\App\Domain\Loan\Services\LoanApprovalService::class)->advisorDecide($loan, $advisor->fresh(), 'APPROVE');
    app(LoanCirculationService::class)->handOut($handler, $loan, [1 => ['condition' => 'FAIR', 'note' => 'สภาพลับเฉพาะ']]);

    $page = $this->get('/verify/'.$loan->ulid)->assertOk();
    $page->assertSee($loan->doc_no)->assertSee(__('loans.loan_status_on_loan'));
    foreach ([...$secrets, 'สภาพลับเฉพาะ', $loan->due_date->format('d/m/Y')] as $secret) {
        $page->assertDontSee($secret);
    }
    // No link into the application and no id.
    expect($page->getContent())->not->toContain(route('loans.show', $loan));
});

test('/verify answers 404 for a DRAFT loan and for an unknown ULID, and still serves requisitions', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $draft = draftLoan($borrower, $asset, 1);

    $this->get('/verify/'.$draft->ulid)->assertNotFound();
    $this->get('/verify/'.\Illuminate\Support\Str::ulid())->assertNotFound();
    // The loan's numeric id is not an address.
    $this->get('/verify/'.$draft->id)->assertNotFound();

    $requisition = makeRequisition($borrower, ['status' => 'SUBMITTED']);
    $this->get('/verify/'.$requisition->ulid)->assertOk()->assertSee($requisition->doc_no)->assertSee(__('requisitions.status_submitted'));

    foreach (['SUBMITTED', 'APPROVED', 'ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED', 'ADVISOR_APPROVED'] as $status) {
        $loan = forceLoanStatus($draft, $status);
        $this->get('/verify/'.$loan->ulid)->assertOk()->assertSee(__('loans.loan_status_'.strtolower($status)));
    }
});

// --- no prices on the counter's pages and on the borrower's ------------------------------------------------------------------

test('no page of step 3 shows the replacement value: not to the scientist at the counter, not to the borrower, not on the slip page', function () {
    [$lab, $asset] = loanBranch();
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '98765.43']);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $asset, 1);
    $out = handedOutLoan($borrower, $asset, 2);

    $pages = [
        [$scientist, route('loans.desk')],
        [$scientist, route('loans.handout.form', $approved)],
        [$scientist, route('loans.return.form', $out)],
        [$scientist, route('loans.show', $out)],
        [$scientist, route('loans.queue')],
        [$borrower, route('loans.show', $out)],
        [$borrower, route('loans.show', $approved)],
        [$borrower, route('loans.mine')],
    ];
    foreach ($pages as [$user, $url]) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('98765')->not->toContain('98,765');
    }
});

// --- the counter's queue -------------------------------------------------------------------------------------------------------

test('the counter page lists the loans of the user\'s OWN branch that wait for hand-out or are out, and nothing else', function () {
    [$labA, $assetA] = loanBranch();
    [$labB, $assetB] = loanBranch();
    $scientistA = scientistUser(['lab_id' => $labA->id]);
    $borrowerA = staffUser(['lab_id' => $labA->id, 'full_name' => 'ผู้ยืมสาขาเอ']);
    $borrowerB = staffUser(['lab_id' => $labB->id, 'full_name' => 'ผู้ยืมสาขาบี']);

    $draft = draftLoan($borrowerA, $assetA, 1);
    $submitted = submittedLoan($borrowerA, $assetA, 1);
    $approved = approvedLoan($borrowerA, $assetA, 1);
    $out = handedOutLoan($borrowerA, $assetA, 2);
    $part = handedOutLoan($borrowerA, $assetA, 2);
    app(LoanCirculationService::class)->receiveReturn($scientistA, $part, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    $closed = handedOutLoan($borrowerA, $assetA, 1);
    app(LoanCirculationService::class)->receiveReturn($scientistA, $closed, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    $approvedB = approvedLoan($borrowerB, $assetB, 1);
    $outB = handedOutLoan($borrowerB, $assetB, 1);

    $page = $this->actingAs($scientistA)->get(route('loans.desk'))->assertOk();
    $page->assertSee($approved->doc_no)->assertSee($out->doc_no)->assertSee($part->doc_no)->assertSee('ผู้ยืมสาขาเอ');
    foreach ([$draft, $submitted, $closed, $approvedB, $outB] as $hidden) {
        $page->assertDontSee($hidden->doc_no);
    }
    $page->assertDontSee('ผู้ยืมสาขาบี')->assertDontSee(__('loans.desk_view_only'));
    $page->assertSee(route('loans.handout.form', $approved), false)->assertSee(route('loans.return.form', $out), false)->assertSee(route('loans.return.form', $part), false);

    $page->assertViewHas('toHandOut', fn ($rows) => $rows->pluck('id')->all() === [$approved->id]);
    $page->assertViewHas('out', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === [$out->id, $part->id]);

    // Another branch's document number typed into the search box finds nothing (and never redirects there).
    $this->actingAs($scientistA)->get(route('loans.desk', ['q' => $outB->doc_no]))->assertOk()->assertDontSee($outB->doc_no.'</a>', false)
        ->assertViewHas('out', fn ($rows) => $rows->isEmpty())->assertViewHas('toHandOut', fn ($rows) => $rows->isEmpty());
});

test('`%`, `_` and a backslash typed (or scanned) into a search box are literal characters, never wildcards: the counter, "my loans" and the branch list', function () {
    [$lab, $pool] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id, 'full_name' => 'สมชาย ทดสอบค้นหา', 'username' => 'somchai.search']);
    $approved = approvedLoan($borrower, $pool, 1);
    $out = handedOutLoan($borrower, $pool, 1);
    $prefix = substr($approved->doc_no, 0, 3);
    expect($approved->doc_no)->not->toContain('%')->not->toContain('_');

    // As a wildcard every one of these would match both documents (and `_` any single character).
    $wild = ['%', '%%', '_', '___', $prefix.'%', '%'.substr($approved->doc_no, -1), str_repeat('_', strlen($approved->doc_no)), $prefix.'\\', '\\'];

    foreach ($wild as $term) {
        // The counter (escaped since step 4's fixes to step 3).
        $this->actingAs($scientist)->get(route('loans.desk', ['q' => $term]))->assertOk()
            ->assertViewHas('toHandOut', fn ($rows) => $rows->isEmpty())->assertViewHas('out', fn ($rows) => $rows->isEmpty());
        // "My loans" and the branch list.
        $this->actingAs($borrower)->get(route('loans.mine', ['q' => $term]))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 0);
        foreach (['q', 'borrower', 'item'] as $field) {
            $this->actingAs($manager)->get(route('loans.branch', [$field => $term]))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 0);
        }
    }

    // Ordinary text still finds by part of the value.
    $this->actingAs($scientist)->get(route('loans.desk', ['q' => $prefix]))->assertOk()
        ->assertViewHas('toHandOut', fn ($rows) => $rows->pluck('id')->all() === [$approved->id])->assertViewHas('out', fn ($rows) => $rows->pluck('id')->all() === [$out->id]);
    $this->actingAs($borrower)->get(route('loans.mine', ['q' => $prefix]))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 2);
    $this->actingAs($manager)->get(route('loans.branch', ['q' => substr($out->doc_no, 2)]))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 1);
    $this->actingAs($manager)->get(route('loans.branch', ['borrower' => 'ทดสอบค้น']))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 2);
    $this->actingAs($manager)->get(route('loans.branch', ['borrower' => 'chai.sea']))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 2);
    $this->actingAs($manager)->get(route('loans.branch', ['item' => mb_substr((string) $pool->item->name_th, 2, 6)]))->assertOk()->assertViewHas('loans', fn ($page) => $page->total() === 2);
});

test('a scan that matches exactly one loan lands on its hand-out or return page; an asset tag works like a document number', function () {
    [$lab, $pool] = loanBranch();
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $pool, 1);
    $out = handedOutLoan($borrower, $unit, 1);

    $this->actingAs($scientist)->get(route('loans.desk', ['q' => $approved->doc_no]))->assertRedirect(route('loans.handout.form', $approved));
    $this->actingAs($scientist)->get(route('loans.desk', ['q' => $out->doc_no]))->assertRedirect(route('loans.return.form', $out));
    $this->actingAs($scientist)->get(route('loans.desk', ['q' => $unit->asset_tag]))->assertRedirect(route('loans.return.form', $out));

    // Several matches (the shared prefix of the document numbers): the list, no redirect.
    $prefix = substr($approved->doc_no, 0, 4);
    $this->actingAs($scientist)->get(route('loans.desk', ['q' => $prefix]))->assertOk()->assertSee($approved->doc_no)->assertSee($out->doc_no);
    // No match: the empty list.
    $this->actingAs($scientist)->get(route('loans.desk', ['q' => 'NO-SUCH-TAG']))->assertOk()->assertSee(__('loans.desk_empty'));
    // SQL wildcards and quotes in the box are just text.
    $this->actingAs($scientist)->get(route('loans.desk', ['q' => "' OR 1=1 --"]))->assertOk()->assertDontSee($approved->doc_no);
});

test('an ADMIN-only account with a branch sees the counter page read-only: the note, no action links, no redirect, and 403 on the forms', function () {
    [$lab, $asset] = loanBranch();
    $admin = adminUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $asset, 1);

    $page = $this->actingAs($admin)->get(route('loans.desk'))->assertOk();
    $page->assertSee($approved->doc_no)->assertSee(__('loans.desk_view_only'));
    $page->assertDontSee(route('loans.handout.form', $approved), false);

    $this->actingAs($admin)->get(route('loans.desk', ['q' => $approved->doc_no]))->assertOk();   // one match, but nothing ADMIN may do with it
    $this->actingAs($admin)->get(route('loans.handout.form', $approved))->assertForbidden();
});

// --- the pages ---------------------------------------------------------------------------------------------------------------------

test('the hand-out page shows every line with a condition field and posts to the loan\'s own hand-out route', function () {
    [$lab, $pool] = loanBranch();
    $unit = loanExtraAsset($lab, 1, makeCountItem(['name_th' => 'เครื่องชั่งตั้งโต๊ะ']), 'UNIT');
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = approvedLoan(staffUser(['lab_id' => $lab->id, 'full_name' => 'ผู้ยืมหน้าเคาน์เตอร์']), [$pool, $unit], 1);

    $html = $this->actingAs($scientist)->get(route('loans.handout.form', $loan))->assertOk()
        ->assertSee($loan->doc_no)->assertSee('ผู้ยืมหน้าเคาน์เตอร์')->assertSee('เครื่องชั่งตั้งโต๊ะ')->assertSee((string) $unit->asset_tag)
        ->assertDontSee(__('loans.self_handout_note'))
        ->getContent();
    $xp = htmlXPath($html);

    expect($xp->query('//form[@action="'.route('loans.handout', $loan).'"][@method="POST"]')->length)->toBe(1);
    foreach ($loan->lines()->get() as $line) {
        expect($xp->query('//select[@name="lines['.$line->line_no.'][condition]"]/option')->length)->toBe(3);
        expect($xp->query('//input[@name="lines['.$line->line_no.'][note]"]')->length)->toBe(1);
    }
    // Quantities are not asked for on this page.
    expect($xp->query('//input[contains(@name, "[qty]")]')->length)->toBe(0);
});

test('the return page offers a quantity (usable, damaged, lost) only for lines that still have pieces out, never above what is out', function () {
    [$lab, $pool] = loanBranch();
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), [$pool, $unit], 1);
    $big = handedOutLoan(staffUser(['lab_id' => $lab->id]), $pool, 4);
    app(LoanCirculationService::class)->receiveReturn($scientist, $big, [1 => ['qty' => '1', 'condition' => 'GOOD']]);

    $xp = htmlXPath($this->actingAs($scientist)->get(route('loans.return.form', $big))->assertOk()->getContent());
    $qty = $xp->query('//input[@name="lines[1][qty]"]');
    expect($qty->length)->toBe(1);
    expect($qty->item(0)->getAttribute('max'))->toBe('3')->and($qty->item(0)->getAttribute('min'))->toBe('0');
    expect($xp->query('//form[@action="'.route('loans.return', $big).'"][@method="POST"]')->length)->toBe(1);
    // Step 4: damaged / lost pieces have their own boxes on the same row (never above what is out) and one description
    // field; the older single "outcome" selector is not on the form.
    foreach (['qty_damaged', 'qty_lost'] as $field) {
        $box = $xp->query('//input[@name="lines[1]['.$field.']"]');
        expect($box->length)->toBe(1);
        expect($box->item(0)->getAttribute('max'))->toBe('3')->and($box->item(0)->getAttribute('min'))->toBe('0')->and($box->item(0)->getAttribute('type'))->toBe('number');
    }
    expect($xp->query('//input[@name="lines[1][incident_note]"][@maxlength="500"]')->length)->toBe(1);
    expect($xp->query('//*[contains(@name, "[outcome]")]')->length)->toBe(0);

    // One line fully back: it has no input any more; the other keeps its own, tagged for the scanner.
    [$lineA, $lineB] = $loan->lines()->get()->all();
    app(LoanCirculationService::class)->receiveReturn($scientist, $loan, [$lineA->line_no => ['qty' => '1', 'condition' => 'GOOD']]);
    $xp = htmlXPath($this->actingAs($scientist)->get(route('loans.return.form', $loan))->assertOk()->getContent());
    expect($xp->query('//input[@name="lines['.$lineA->line_no.'][qty]"]')->length)->toBe(0);
    expect($xp->query('//input[starts-with(@name, "lines['.$lineA->line_no.'][")]')->length)->toBe(0);   // nor the step-4 boxes
    $left = $xp->query('//input[@name="lines['.$lineB->line_no.'][qty]"]');
    expect($left->length)->toBe(1);
    expect($left->item(0)->getAttribute('data-tag'))->toBe((string) $unit->asset_tag);
});

test('the loan page after hand-out shows what is out, what came back, the condition, the movement history and the right buttons per viewer', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id, 'full_name' => 'นักวิทย์ประจำเคาน์เตอร์']);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = approvedLoan($borrower, $asset, 3);

    // APPROVED: the counter sees "hand out", the borrower does not; nobody sees "return"; both see "print".
    $this->actingAs($scientist)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(route('loans.handout.form', $loan), false)->assertDontSee(route('loans.return.form', $loan), false)->assertSee(route('loans.pdf', $loan), false)
        ->assertDontSee(__('loans.section_movements'));
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertDontSee(route('loans.handout.form', $loan), false)->assertSee(route('loans.pdf', $loan), false);

    app(LoanCirculationService::class)->handOut($scientist, $loan, [1 => ['condition' => 'FAIR', 'note' => 'ขาตั้งหลวมเล็กน้อย']]);
    app(LoanCirculationService::class)->receiveReturn($scientist, $loan, [1 => ['qty' => '1', 'condition' => 'FAIR']]);

    $page = $this->actingAs($scientist)->get(route('loans.show', $loan))->assertOk();
    $page->assertSee(__('loans.loan_status_partially_returned'))
        ->assertSee(__('loans.section_movements'))->assertSee(__('loans.movement_handout'))->assertSee(__('loans.movement_return_ok'))
        ->assertSee(__('loans.condition_fair'))->assertSee('ขาตั้งหลวมเล็กน้อย')->assertSee('นักวิทย์ประจำเคาน์เตอร์')
        ->assertSee(__('loans.col_outstanding'))->assertSee(__('loans.section_due_history'))->assertSee(__('loans.no_due_changes'))
        ->assertSee(route('loans.return.form', $loan), false)->assertDontSee(route('loans.handout.form', $loan), false)
        // Cancelling is over once things went out.
        ->assertDontSee(route('loans.cancel', $loan), false);
    $page->assertViewHas('loan', fn (Loan $shown) => $shown->movements->pluck('movement_type')->all() === ['HANDOUT', 'RETURN_OK']);

    // The borrower: the same facts, the renewal form, no counter buttons.
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(__('loans.section_movements'))->assertSee(route('loans.renewals.store', $loan), false)->assertSee(__('loans.renew_first_note'))
        ->assertDontSee(route('loans.return.form', $loan), false)->assertDontSee(route('loans.cancel', $loan), false);

    app(LoanCirculationService::class)->receiveReturn($scientist, $loan, [1 => ['qty' => '2', 'condition' => 'FAIR']]);
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(__('loans.loan_status_closed'))->assertSee(__('loans.field_closed_at'))
        ->assertDontSee(route('loans.renewals.store', $loan), false);
    $this->actingAs($scientist)->get(route('loans.show', $loan))->assertOk()->assertDontSee(route('loans.return.form', $loan), false);
});

test('an overdue loan carries the computed "overdue N days" mark on the loan page, the counter page and the lists, and the overdue-only filter finds it', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $late = handedOutLoan($borrower, $asset, 1);
    $onTime = handedOutLoan($borrower, $asset, 1, null, loanDates(20));
    $mark = __('loans.overdue_badge', ['days' => 3]);

    $this->actingAs($manager)->get(route('loans.show', $late))->assertOk()->assertDontSee(__('loans.overdue_badge', ['days' => 0]));

    $this->travelTo($late->due_date->copy()->addDays(3)->setTime(10, 0));

    $this->actingAs($manager)->get(route('loans.show', $late))->assertOk()->assertSee($mark);
    $this->actingAs($manager)->get(route('loans.show', $onTime))->assertOk()->assertDontSee($mark);
    $this->actingAs($manager)->get(route('loans.desk'))->assertOk()->assertSee($mark);
    $this->actingAs($borrower)->get(route('loans.mine'))->assertOk()->assertSee($mark);

    $this->actingAs($borrower)->get(route('loans.mine', ['overdue' => '1']))->assertOk()->assertSee($late->doc_no)->assertDontSee($onTime->doc_no);
    $this->actingAs($manager)->get(route('loans.branch', ['overdue' => '1']))->assertOk()->assertSee($late->doc_no)->assertDontSee($onTime->doc_no);
    $this->actingAs($manager)->get(route('loans.branch'))->assertOk()->assertSee($late->doc_no)->assertSee($onTime->doc_no);
    // Only "1" is a value of the filter.
    $this->actingAs($manager)->get(route('loans.branch', ['overdue' => 'yes']))->assertSessionHasErrors('overdue');
});

test('every step-3 page has a matching id for each label, and scrollable tables are keyboard-focusable', function () {
    [$lab, $asset] = loanBranch();
    $manager = auditorUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approved = approvedLoan($borrower, $asset, 1);
    $out = handedOutLoan($borrower, $asset, 2);
    // A waiting renewal, so the decision form is on the page too.
    $renewals = app(\App\Domain\Loan\Services\LoanRenewalService::class);
    $renewals->request($borrower->fresh(), $out, $out->due_date->copy()->addDays(2)->format('Y-m-d'), null);
    $renewals->request($borrower->fresh(), $out->fresh(), $out->due_date->copy()->addDays(4)->format('Y-m-d'), 'ยังใช้งานไม่เสร็จ');

    $pages = [
        [$manager, route('loans.desk')], [$manager, route('loans.handout.form', $approved)], [$manager, route('loans.return.form', $out)],
        [$manager, route('loans.show', $out)], [$manager, route('loans.queue')], [$borrower, route('loans.show', $out)],
    ];
    foreach ($pages as [$user, $url]) {
        $xp = htmlXPath($this->actingAs($user)->get($url)->assertOk()->getContent());

        foreach ($xp->query('//label[@for]') as $label) {
            $target = $label->getAttribute('for');
            expect($xp->query('//*[@id="'.$target.'"]')->length)->toBe(1, "{$url}: label for={$target} must have exactly one target");
        }
        foreach ($xp->query('//*[contains(@class, "overflow-x-auto")]') as $scroller) {
            expect($scroller->hasAttribute('tabindex'))->toBeTrue("{$url}: scrollable region without tabindex");
        }
    }
});

// --- the menu: the tab and the badges ---------------------------------------------------------------------------------------------

test('the counter, the hand-out page and the return page open inside the loan card with the "hand-out / return" tab marked, once', function () {
    $manager = navUser('auditor', ['person_type' => 'STAFF']);
    $asset = loanExtraAsset(Lab::findOrFail($manager->lab_id), 5);
    $borrower = staffUser(['lab_id' => $manager->lab_id]);
    $approved = approvedLoan($borrower, $asset, 1);
    $out = handedOutLoan($borrower, $asset, 1);

    foreach ([route('loans.desk'), route('loans.handout.form', $approved), route('loans.return.form', $out)] as $url) {
        $nav = layoutNav($this->actingAs($manager)->get($url)->assertOk()->getContent());

        expect($nav['title'])->toBe(__('nav.card_loan'), $url);
        $active = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
        expect($active)->toHaveCount(1);
        expect($active[0]['label'])->toBe(__('nav.tab_loan_desk'), $url);
        expect($active[0]['href'])->toBe(route('loans.desk'));
    }
});

test('the loan card\'s badges count what the counter of the user\'s own branch has to do, and only for people who have the counter tab', function () {
    [$labA, $assetA] = loanBranch();
    [$labB, $assetB] = loanBranch();
    $scientist = navUser('scientist', ['lab_id' => $labA->id]);
    $borrowerA = staffUser(['lab_id' => $labA->id]);
    $borrowerB = staffUser(['lab_id' => $labB->id]);
    $badges = fn (User $user) => collect(app(MenuRegistry::class)->cardsFor($user))->firstWhere('key', MenuRegistry::LOAN)['badges'] ?? [];
    $texts = fn (User $user) => array_column($badges($user), 'text');
    $handout = fn (int $n) => (string) __('nav.badge_loan_handout', ['count' => $n]);
    $overdue = fn (int $n) => (string) __('nav.badge_loan_overdue', ['count' => $n]);

    // Nothing to do: no counter badge at all.
    expect($texts($scientist))->not->toContain($handout(0))->not->toContain($overdue(0));

    approvedLoan($borrowerA, $assetA, 1);
    approvedLoan($borrowerA, $assetA, 1);
    $out = handedOutLoan($borrowerA, $assetA, 1);
    approvedLoan($borrowerB, $assetB, 1);          // another branch: not counted
    handedOutLoan($borrowerB, $assetB, 1);
    submittedLoan($borrowerA, $assetA, 1);         // not approved yet: not counted

    expect($texts($scientist))->toContain($handout(2))->not->toContain($overdue(1));

    $this->travelTo($out->due_date->copy()->addDays(2)->setTime(8, 0));
    expect($texts($scientist))->toContain($handout(2))->toContain($overdue(1));

    // The home page really draws them on the loan card.
    $cards = collect(homeCards($this->actingAs($scientist)->get('/')->assertOk()->getContent()))->keyBy('title');
    expect($cards[__('nav.card_loan')]['badges'])->toContain($handout(2))->toContain($overdue(1));

    // A borrower of the same branch has no counter badge; a scientist with no branch neither.
    foreach ([$borrowerA, studentUser(['lab_id' => $labA->id]), navUser('scientist', ['lab_id' => null])] as $user) {
        foreach ($texts($user) as $text) {
            expect($text)->not->toBe($handout(2))->not->toBe($overdue(1));
        }
    }
    // The other branch's scientist counts their own branch only.
    expect($texts(navUser('scientist', ['lab_id' => $labB->id])))->toContain($handout(1))->toContain($overdue(1));
});

// --- the "ready for hand-out" notice (written, NOT wired yet) ---------------------------------------------------------------------

test('LoanNotifier::readyForHandout tells the counter of the branch in the system, emails the warehouse managers only, and says nothing personal', function () {
    [$lab, $asset] = loanBranch();
    $other = makeLab();
    $borrower = staffUser(['lab_id' => $lab->id, 'full_name' => 'ผู้ยืมที่ไม่ควรอยู่ในข้อความ']);
    $approver = scientistUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $silent = [
        'the approver who just decided' => $approver,
        'the borrower' => $borrower,
        'ADMIN of the branch (not a handler)' => adminUser(['lab_id' => $lab->id]),
        'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'manager of another branch' => labManagerUser(['lab_id' => $other->id]),
        'scientist with no branch' => scientistUser(['lab_id' => null]),
        'inactive scientist' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
        'STAFF of the branch' => staffUser(['lab_id' => $lab->id]),
    ];
    $notifier = app(LoanNotifier::class);
    $type = LoanNotifier::TYPE_READY_HANDOUT;

    // Not APPROVED yet: nobody is told.
    $notifier->readyForHandout(submittedLoan($borrower, $asset, 1), $approver);
    expect(loanNotices($type))->toHaveCount(0);

    $loan = approvedLoan($borrower, $asset, 1, $approver);
    $notifier->readyForHandout($loan, $approver);

    $notices = loanNotices($type);
    expect($notices->pluck('user_id')->sort()->values()->all())->toBe(collect([$scientist->id, $manager->id])->sort()->values()->all());
    foreach ($notices as $notice) {
        expect($notice->link_url)->toBe(route('loans.handout.form', $loan));
        expect($notice->body)->toContain($loan->doc_no)->toContain($loan->loan_date->format('d/m/Y'));
        expect($notice->title.$notice->body)->not->toContain('ผู้ยืมที่ไม่ควรอยู่ในข้อความ')->not->toContain((string) $borrower->email);
    }
    expect(loanMailCount($manager))->toBe(1)->and(loanMailCount($scientist))->toBe(0);
    foreach ($silent as $who => $user) {
        expect(Notification::where('user_id', $user->id)->where('type', $type)->count())->toBe(0, "{$who} must not be told");
        expect(loanMailCount($user))->toBe(0, "{$who} must not be emailed");
    }
});

test('[DECISION] approving a loan does not yet tell the counter (readyForHandout is not called by any controller)', function () {
    // QA fix (ITM-2610-051): this used to PASS on the gap itself, so a missing feature counted as a green test. It is
    // skipped until the CEO decides; the body below is what happens today. When the call is wired (or the CEO decides
    // it never will be), rewrite this test for the decided behaviour together with the step-2 notification tests
    // that pin "nobody at the counter is told".
    $this->markTestSkipped('รอ CEO ตัดสินว่าจะแจ้งเคาน์เตอร์เมื่ออนุมัติหรือไม่ (ITM-2610-051)');
    [$lab, $asset] = loanBranch();
    $approver = scientistUser(['lab_id' => $lab->id]);
    $counter = auditorUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    loanManagerPost($this, $approver, $loan)->assertRedirect();

    expect($loan->fresh()->status)->toBe('APPROVED');
    expect(loanNotices(LoanNotifier::TYPE_READY_HANDOUT))->toHaveCount(0);
    // The counter still finds it on the counter page.
    $this->actingAs($counter)->get(route('loans.desk'))->assertOk()->assertSee($loan->doc_no);
});

// =====================================================================================================================
// THE SELF RULE (waits for the CEO's confirmation).
// Today: the borrower MAY hand out / take back their own loan when they are a handler of the branch; the control is the
// audit row (LOAN_SELF_HANDOUT / LOAN_SELF_RETURN, handler_is_borrower = true). The spec's BR-L04 said "never the
// borrower". If the CEO decides to forbid it, the tests of this section are the ones to flip (expect 403 / an
// exception and no movement), nothing above changes.
// =====================================================================================================================

test('[SELF] a handler who is the borrower hands out and takes back their own loan, and it is recorded as SELF, never as an ordinary hand-out', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $me = navUser($kind, ['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $loan = approvedLoan($me, $asset, 2);   // approved by somebody else of the branch

    $this->actingAs($me)->get(route('loans.handout.form', $loan))->assertOk()->assertSee(__('loans.self_handout_note'));
    loanHandoutPost($this, $me, $loan)->assertRedirect(route('loans.show', $loan));

    expect($loan->fresh()->status)->toBe('ON_LOAN');
    $self = loanAudit('LOAN_SELF_HANDOUT', $loan);
    expect($self)->toHaveCount(1)->and(loanAudit('LOAN_HANDOUT', $loan))->toHaveCount(0);
    expect($self[0]->user_id)->toBe($me->id)->and($self[0]->message)->toBe('borrower = handler');
    expect($self[0]->new_value['handler_is_borrower'])->toBeTrue()->and($self[0]->new_value['handler_id'])->toBe($me->id);
    expect($self[0]->new_value['borrower_id'])->toBe($me->id)->and($self[0]->new_value['handler_is_approver'])->toBeFalse();

    $this->actingAs($me)->get(route('loans.return.form', $loan))->assertOk()->assertSee(__('loans.self_handout_note'));
    loanReturnPost($this, $me, $loan, [1 => ['qty' => '2', 'condition' => 'GOOD']])->assertRedirect(route('loans.show', $loan));

    expect($loan->fresh()->status)->toBe('CLOSED');
    $selfReturn = loanAudit('LOAN_SELF_RETURN', $loan);
    expect($selfReturn)->toHaveCount(1)->and(loanAudit('LOAN_RETURN', $loan))->toHaveCount(0);
    expect($selfReturn[0]->new_value['handler_is_borrower'])->toBeTrue()->and($selfReturn[0]->message)->toBe('borrower = handler');
})->with(['scientist', 'auditor', 'lab_manager', 'admin+scientist']);

test('[SELF] one person may ask, approve and hand out their own loan; the audit trail says so three times', function () {
    [$lab, $asset] = loanBranch();
    $me = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
    $loan = submittedLoan($me, $asset, 1);

    loanManagerPost($this, $me, $loan)->assertRedirect();
    loanHandoutPost($this, $me, $loan)->assertRedirect(route('loans.show', $loan));

    expect(loanAudit('LOAN_SELF_APPROVE', $loan))->toHaveCount(1);
    $handout = loanAudit('LOAN_SELF_HANDOUT', $loan);
    expect($handout)->toHaveCount(1);
    expect($handout[0]->new_value['handler_is_borrower'])->toBeTrue()->and($handout[0]->new_value['handler_is_approver'])->toBeTrue();
});

test('[SELF] the audit row also states the plain facts when the handler approved the loan or typed it in for the borrower', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $other = scientistUser(['lab_id' => $lab->id]);

    // The handler approved it.
    $approvedByHandler = approvedLoan($borrower, $asset, 1, $scientist);
    loanHandoutPost($this, $scientist, $approvedByHandler)->assertRedirect();
    $row = loanAudit('LOAN_HANDOUT', $approvedByHandler)[0];
    expect($row->new_value['handler_is_approver'])->toBeTrue()->and($row->new_value['handler_is_borrower'])->toBeFalse()->and($row->new_value['handler_is_creator'])->toBeFalse();
    expect($row->message)->toBeNull();

    // The handler typed it in for the borrower (somebody else approved).
    $onBehalf = submittedLoan($borrower, $asset, 1, $scientist);
    app(\App\Domain\Loan\Services\LoanApprovalService::class)->managerDecide($onBehalf, $other, 'APPROVE');
    loanHandoutPost($this, $scientist, $onBehalf)->assertRedirect();
    $row = loanAudit('LOAN_HANDOUT', $onBehalf)[0];
    expect($row->new_value['handler_is_creator'])->toBeTrue()->and($row->new_value['handler_is_approver'])->toBeFalse()->and($row->new_value['handler_is_borrower'])->toBeFalse();
});

test('[SELF] a borrower who is NOT a handler of the branch still cannot hand out or take back their own loan', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $other = makeLab();
    $me = match ($kind) {
        'STAFF' => staffUser(['lab_id' => $lab->id]),
        'STUDENT' => studentUser(['lab_id' => $lab->id]),
        'ADMIN alone' => adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]),
    };
    $approved = approvedLoan($me, $asset, 1);
    $out = handedOutLoan($me, $asset, 1);
    $beforeApproved = loanCounterState($approved);
    $beforeOut = loanCounterState($out);

    $this->actingAs($me)->get(route('loans.handout.form', $approved))->assertForbidden();
    loanHandoutPost($this, $me, $approved)->assertForbidden();
    $this->actingAs($me)->get(route('loans.return.form', $out))->assertForbidden();
    loanReturnPost($this, $me, $out, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->handOut($me->fresh(), $approved, loanConditions($approved)))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($me->fresh(), $out, [1 => ['qty' => '1', 'condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.handler_not_eligible'));

    expect(loanCounterState($approved))->toBe($beforeApproved)->and(loanCounterState($out))->toBe($beforeOut);
    expect($other->id)->not->toBe($lab->id);
})->with(['STAFF', 'STUDENT', 'ADMIN alone']);
