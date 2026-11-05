<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanService;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanAsset;
use App\Models\LoanLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 2: the borrower's side — the request form, draft / submit, cancel, the lists and who may open a
 * document — through the REAL routes. The things that decide who may approve (branch, student -> advisor) are all
 * set by the server from the borrower's own profile; the form can say what and how many, nothing else.
 *
 * (Written without being run: see the report to the secretary.)
 */

beforeEach(function () {
    Mail::fake();
});

// --- the branch gate (BR-L05) and the profile gate (BR-L14) ----------------------------------------------------

test('a user with no branch cannot open the form or post a request: they go to the "waiting for a branch" page and nothing is created', function (string $kind) {
    [, $asset] = loanBranch();
    $user = match ($kind) {
        'student' => studentUser(['lab_id' => null]),
        'staff' => staffUser(['lab_id' => null]),
        'scientist' => scientistUser(['lab_id' => null, 'person_type' => 'STAFF']),
        'ADMIN' => adminUser(['lab_id' => null, 'person_type' => 'STAFF']),
    };

    $this->actingAs($user)->get(route('loans.create'))->assertRedirect(route('loans.pending-lab'));
    $this->actingAs($user)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertRedirect(route('loans.pending-lab'));
    expect(Loan::count())->toBe(0);

    $this->actingAs($user)->get(route('loans.pending-lab'))->assertOk()->assertSee(__('loans.pending_lab_title'));
    $this->actingAs($user)->get(route('loans.mine'))->assertOk()->assertSee(__('loans.no_lab_note'));
})->with(['student', 'staff', 'scientist', 'ADMIN']);

test('a user who has a branch is not kept on the "waiting for a branch" page', function () {
    $staff = staffUser(['lab_id' => makeLab()->id]);

    $this->actingAs($staff)->get(route('loans.pending-lab'))->assertRedirect(route('loans.mine'));
});

test('an ADMIN with a branch borrows for themself, from that branch only (BR-L05)', function () {
    [$lab, $asset] = loanBranch();
    [, $otherAsset] = loanBranch();
    $admin = adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);

    $this->actingAs($admin)->post(route('loans.store'), loanPayload([$otherAsset->ulid => '1']))
        ->assertSessionHasErrors(['loan' => __('loans.error.asset_other_lab')]);
    expect(Loan::count())->toBe(0);

    $this->actingAs($admin)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();
    expect($loan->lab_id)->toBe($lab->id)->and($loan->borrower_id)->toBe($admin->id)->and($loan->borrower_status)->toBe('STAFF');
});

test('an incomplete profile is sent to complete-profile before any request: no person type, or a student with no advisor', function () {
    [$lab, $asset] = loanBranch();
    $noType = staffUser(['lab_id' => $lab->id, 'person_type' => null]);
    $noAdvisor = studentUser(['lab_id' => $lab->id, 'advisor_id' => null]);

    foreach ([$noType, $noAdvisor] as $user) {
        $this->actingAs($user)->get(route('loans.create'))->assertRedirect(route('account.complete-profile'));
        $this->actingAs($user)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertRedirect(route('account.complete-profile'));
    }
    expect(Loan::count())->toBe(0);
});

// --- draft, submit ------------------------------------------------------------------------------------------------

test('a staff member saves a draft and submits it: every field the workflow depends on is set by the server, and both steps are audited', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);

    $response = $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '3'], ['purpose_detail' => 'ทดลองในรายวิชา']));
    $loan = Loan::firstOrFail();
    $response->assertRedirect(route('loans.show', $loan));

    expect($loan->status)->toBe('DRAFT')->and($loan->lab_id)->toBe($lab->id)->and($loan->borrower_id)->toBe($staff->id)->and($loan->created_by)->toBe($staff->id);
    expect($loan->borrower_status)->toBe('STAFF')->and($loan->advisor_id)->toBeNull()->and($loan->submitted_at)->toBeNull();
    expect($loan->doc_no)->toMatch('/^LON-\d{4}-\d{5}$/');
    expect($loan->ulid)->toHaveLength(26);
    expect($loan->original_due_date->toDateString())->toBe($loan->due_date->toDateString());
    $line = $loan->lines()->firstOrFail();
    expect($line->line_no)->toBe(1)->and($line->loan_asset_id)->toBe($asset->id)->and($line->qty)->toBe('3.000000')->and($line->qty_approved)->toBeNull();
    $created = loanAudit('LOAN_CREATE', $loan);
    expect($created)->toHaveCount(1);
    expect($created[0]->new_value['on_behalf'])->toBeFalse();
    $this->actingAs($staff)->get(route('loans.show', $loan))->assertOk()->assertSee($loan->doc_no);

    $this->actingAs($staff)->post(route('loans.submit', $loan))->assertRedirect(route('loans.show', $loan));
    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('SUBMITTED')->and($fresh->submitted_at)->not->toBeNull()->and($fresh->borrower_status)->toBe('STAFF');
    expect(loanAudit('LOAN_SUBMIT', $loan))->toHaveCount(1);

    // A submitted document cannot be submitted again.
    $this->actingAs($staff)->post(route('loans.submit', $loan))->assertForbidden();
});

test('"save and submit" in one go; a student\'s request takes the advisor from the PROFILE', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);

    $this->actingAs($student)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();

    $loan = Loan::firstOrFail();
    expect($loan->status)->toBe('SUBMITTED')->and($loan->borrower_status)->toBe('STUDENT')->and($loan->advisor_id)->toBe($student->advisor_id);
    expect($loan->advisor_signed_at)->toBeNull();
});

test('the owner edits a draft: lines and header change, the borrower, branch, status and number never do, whatever is posted', function () {
    [$lab, $asset] = loanBranch();
    $asset2 = loanExtraAsset($lab, 5);
    $staff = staffUser(['lab_id' => $lab->id]);
    $loan = draftLoan($staff, $asset, 2);
    $number = $loan->doc_no;

    $this->actingAs($staff)->get(route('loans.edit', $loan))->assertOk();
    $this->actingAs($staff)->put(route('loans.update', $loan), loanPayload([$asset->ulid => '1', $asset2->ulid => '4'], [
        'purpose_type' => 'RESEARCH', 'status' => 'APPROVED', 'borrower_status' => 'STUDENT', 'advisor_id' => $staff->id,
        'lab_id' => makeLab()->id, 'borrower_id' => 999999, 'created_by' => 999999, 'doc_no' => 'LON-0000-00000', 'approved_by' => $staff->id,
    ]))->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->purpose_type)->toBe('RESEARCH')->and($fresh->doc_no)->toBe($number);
    expect($fresh->status)->toBe('DRAFT')->and($fresh->borrower_status)->toBe('STAFF')->and($fresh->advisor_id)->toBeNull();
    expect($fresh->lab_id)->toBe($lab->id)->and($fresh->borrower_id)->toBe($staff->id)->and($fresh->created_by)->toBe($staff->id)->and($fresh->approved_by)->toBeNull();
    $lines = $fresh->lines()->get();
    expect($lines)->toHaveCount(2);
    expect($lines[0]->qty)->toBe('1.000000')->and($lines[1]->qty)->toBe('4.000000');
});

// --- on behalf of a borrower (FR-LN-14) ---------------------------------------------------------------------------

test('counter staff (loan.handout) type a request in for a borrower of their own branch; the creator is recorded, the snapshot is the BORROWER\'s', function () {
    [$lab, $asset] = loanBranch();
    $counter = scientistUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);

    $response = $this->actingAs($counter)->post(route('loans.store'), loanPayload([$asset->ulid => '2'], ['borrower' => $borrower->ulid]));
    $loan = Loan::firstOrFail();
    $response->assertRedirect(route('loans.show', $loan));

    expect($loan->borrower_id)->toBe($borrower->id)->and($loan->created_by)->toBe($counter->id)->and($loan->lab_id)->toBe($lab->id);
    expect($loan->borrower_status)->toBe('STAFF')->and($loan->isOnBehalf())->toBeTrue();
    expect(loanAudit('LOAN_CREATE', $loan)[0]->new_value['on_behalf'])->toBeTrue();

    // Both the borrower and the person who typed it in see it, and both may take it forward.
    $this->actingAs($counter)->get(route('loans.show', $loan))->assertOk();
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk();
    $this->actingAs($counter)->post(route('loans.submit', $loan))->assertRedirect();
    expect($loan->fresh()->status)->toBe('SUBMITTED');
});

test('FR-LN-14 / BR-L14: typing in for a STUDENT cannot make them a staff member, pick the advisor or the branch, or skip the advisor', function () {
    [$lab, $asset] = loanBranch();
    $counter = scientistUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => $lab->id]);
    $evilAdvisor = navUser('advisor');

    $this->actingAs($counter)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], [
        'borrower' => $student->ulid, 'action' => 'submit',
        'borrower_status' => 'STAFF', 'advisor_id' => $evilAdvisor->id, 'status' => 'APPROVED', 'lab_id' => makeLab()->id,
        'borrower_id' => $counter->id, 'created_by' => 999999,
    ]))->assertSessionHasNoErrors();

    $loan = Loan::firstOrFail();
    expect($loan->borrower_status)->toBe('STUDENT')->and($loan->advisor_id)->toBe($student->advisor_id)->and($loan->status)->toBe('SUBMITTED');
    expect($loan->borrower_id)->toBe($student->id)->and($loan->created_by)->toBe($counter->id)->and($loan->lab_id)->toBe($lab->id);

    // The counter cannot approve it, and no other approver can until the advisor has.
    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertForbidden();
    expect($loan->fresh()->status)->toBe('SUBMITTED');
});

test('only holders of loan.handout may type a request in for somebody else; everyone else gets 403 and nothing is created', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);

    foreach ([staffUser(['lab_id' => $lab->id]), studentUser(['lab_id' => $lab->id]), navUser('advisor', ['lab_id' => $lab->id])] as $user) {
        $this->actingAs($user)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid]))->assertForbidden();
    }
    expect(Loan::count())->toBe(0);
});

test('on behalf: a counter of one branch cannot serve another branch, a borrower with no branch, themself, an inactive or a non-borrowing account', function (string $case, string $message) {
    [$lab, $asset] = loanBranch();
    $otherLab = makeLab();
    $counter = scientistUser(['lab_id' => $lab->id]);

    $borrower = match ($case) {
        'other branch' => staffUser(['lab_id' => $otherLab->id]),
        'no branch' => staffUser(['lab_id' => null]),
        'themself' => $counter,
        'inactive' => staffUser(['lab_id' => $lab->id, 'is_active' => false]),
        'holds no borrowing permission' => userWithPermissions(['audit.view'], ['lab_id' => $lab->id, 'person_type' => 'STAFF']),
    };

    $this->actingAs($counter)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid]))
        ->assertSessionHasErrors(['loan' => __($message)]);
    expect(Loan::count())->toBe(0);
})->with([
    'other branch' => ['other branch', 'loans.error.borrower_other_lab'],
    'no branch' => ['no branch', 'loans.error.borrower_no_lab'],
    'themself' => ['themself', 'loans.error.borrower_invalid'],
    'inactive' => ['inactive', 'loans.error.borrower_invalid'],
    'holds no borrowing permission' => ['holds no borrowing permission', 'loans.error.borrower_invalid'],
]);

test('on behalf: an unknown borrower id is a validation error', function () {
    [$lab, $asset] = loanBranch();
    $counter = scientistUser(['lab_id' => $lab->id]);

    $this->actingAs($counter)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => (string) Str::ulid()]))->assertSessionHasErrors('borrower');
    expect(Loan::count())->toBe(0);
});

test('on behalf: ADMIN (no branch of their own) serves a borrower of any branch, but the document stays in the BORROWER\'s branch (BR-L05)', function () {
    [$labA, $assetA] = loanBranch();
    [, $assetB] = loanBranch();
    $admin = adminUser(['lab_id' => null]);
    $borrower = staffUser(['lab_id' => $labA->id]);

    // A line from another branch is refused even though ADMIN is not tied to a branch.
    $this->actingAs($admin)->post(route('loans.store'), loanPayload([$assetB->ulid => '1'], ['borrower' => $borrower->ulid]))
        ->assertSessionHasErrors(['loan' => __('loans.error.asset_other_lab')]);
    expect(Loan::count())->toBe(0);

    // A borrower with no branch cannot be served at all.
    $this->actingAs($admin)->post(route('loans.store'), loanPayload([$assetA->ulid => '1'], ['borrower' => staffUser(['lab_id' => null])->ulid]))
        ->assertSessionHasErrors(['loan' => __('loans.error.borrower_no_lab')]);

    $this->actingAs($admin)->post(route('loans.store'), loanPayload([$assetA->ulid => '1'], ['borrower' => $borrower->ulid]))->assertSessionHasNoErrors();
    $loan = Loan::firstOrFail();
    expect($loan->lab_id)->toBe($labA->id)->and($loan->borrower_id)->toBe($borrower->id)->and($loan->created_by)->toBe($admin->id);
});

test('the borrower search on the form lists only borrowers the counter may serve: own branch for staff, any branch for ADMIN; never inactive, never themself', function () {
    [$lab] = loanBranch();
    $otherLab = makeLab();
    $counter = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);   // a complete profile, so the form opens
    staffUser(['lab_id' => $lab->id, 'full_name' => 'ZZBQ ในสาขา']);
    staffUser(['lab_id' => $otherLab->id, 'full_name' => 'ZZBQ ต่างสาขา']);
    staffUser(['lab_id' => $lab->id, 'full_name' => 'ZZBQ ปิดใช้งาน', 'is_active' => false]);
    userWithPermissions(['audit.view'], ['lab_id' => $lab->id, 'full_name' => 'ZZBQ ไม่มีสิทธิ์ยืม']);

    $this->actingAs($counter)->get(route('loans.create', ['bq' => 'ZZBQ']))->assertOk()
        ->assertSee('ZZBQ ในสาขา')
        ->assertDontSee('ZZBQ ต่างสาขา')->assertDontSee('ZZBQ ปิดใช้งาน')->assertDontSee('ZZBQ ไม่มีสิทธิ์ยืม');

    $admin = adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $this->actingAs($admin)->get(route('loans.create', ['bq' => 'ZZBQ']))->assertOk()
        ->assertSee('ZZBQ ในสาขา')->assertSee('ZZBQ ต่างสาขา')->assertDontSee('ZZBQ ปิดใช้งาน')->assertDontSee('ZZBQ ไม่มีสิทธิ์ยืม');

    // Somebody who may not type requests in for others gets no list at all.
    $this->actingAs(staffUser(['lab_id' => $lab->id]))->get(route('loans.create', ['bq' => 'ZZBQ']))->assertOk()->assertDontSee('ZZBQ ในสาขา');
});

// --- mass assignment ------------------------------------------------------------------------------------------------

test('mass assignment: only the free-form request details are fillable; status, snapshot, branch, people and every decision column are not', function () {
    $loan = new Loan();

    expect($loan->getFillable())->toEqualCanonicalizing(['purpose_type', 'purpose_detail', 'loan_date', 'due_date', 'remark']);
    foreach ([
        'status', 'borrower_status', 'advisor_id', 'lab_id', 'borrower_id', 'created_by', 'doc_no', 'ulid', 'approved_by', 'approved_at',
        'advisor_signed_at', 'submitted_at', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'reject_reason', 'original_due_date', 'renew_count',
    ] as $column) {
        expect($loan->isFillable($column))->toBeFalse($column);
    }

    $line = new LoanLine();
    foreach (['loan_id', 'qty_approved', 'qty_returned_ok', 'qty_returned_damaged', 'qty_lost', 'condition_out'] as $column) {
        expect($line->isFillable($column))->toBeFalse($column);
    }
});

test('mass assignment through the form: posted status, borrower_status, advisor_id, lab_id and quantities for approval change nothing', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $stranger = navUser('advisor');

    $this->actingAs($student)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], [
        'status' => 'APPROVED', 'borrower_status' => 'STAFF', 'advisor_id' => $stranger->id, 'lab_id' => makeLab()->id,
        'qty_approved' => [1 => '1'], 'approved_by' => $stranger->id, 'advisor_signed_at' => now()->toDateTimeString(),
    ]))->assertSessionHasNoErrors();

    $loan = Loan::firstOrFail();
    expect($loan->status)->toBe('DRAFT')->and($loan->borrower_status)->toBe('STUDENT')->and($loan->advisor_id)->toBe($student->advisor_id);
    expect($loan->lab_id)->toBe($lab->id)->and($loan->approved_by)->toBeNull()->and($loan->advisor_signed_at)->toBeNull();
    expect($loan->lines()->firstOrFail()->qty_approved)->toBeNull();
});

// --- BR-L14: the snapshot ---------------------------------------------------------------------------------------------

test('BR-L14: after SUBMIT the status snapshot and the advisor are fixed — a later profile change does not move the document, and the form cannot edit it', function () {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $loan = submittedLoan($student, $asset, 1);
    $newAdvisor = navUser('advisor');

    // The profile changes afterwards (an admin, or by hand).
    $student->forceFill(['person_type' => 'STAFF', 'advisor_id' => $newAdvisor->id])->save();

    $fresh = $loan->fresh();
    expect($fresh->borrower_status)->toBe('STUDENT')->and($fresh->advisor_id)->toBe($advisor->id);
    loanAdvisorPost($this, $newAdvisor, $loan)->assertForbidden();     // the NEW advisor has no say on the old document
    loanManagerPost($this, scientistUser(['lab_id' => $lab->id]), $loan)->assertForbidden();   // and it still has to pass the old advisor
    loanAdvisorPost($this, $advisor, $loan)->assertRedirect();
    expect($loan->fresh()->status)->toBe('APPROVED');   // CEO 2026-11-05: the advisor's approval is the last one

    // No editing a submitted document, by the form or by the service.
    $student = $student->fresh();
    $this->actingAs($student)->get(route('loans.edit', $loan))->assertForbidden();
    $this->actingAs($student)->put(route('loans.update', $loan), loanPayload([$asset->ulid => '1'], ['borrower_status' => 'STAFF', 'advisor_id' => $newAdvisor->id]))->assertForbidden();
    expect(fn () => app(LoanService::class)->updateDraft($student, $loan, loanData(), [$asset->ulid => '1']))
        ->toThrow(LoanDomainException::class, __('loans.error.not_draft'));
    expect($loan->fresh()->advisor_id)->toBe($advisor->id)->and($loan->fresh()->borrower_status)->toBe('STUDENT');
});

test('the snapshot is taken at SUBMIT: a draft made as STAFF and then changed to STUDENT is submitted as STUDENT with the advisor of that moment', function () {
    [$lab, $asset] = loanBranch();
    $user = staffUser(['lab_id' => $lab->id]);
    $loan = draftLoan($user, $asset, 1);
    expect($loan->borrower_status)->toBe('STAFF');

    $advisor = navUser('advisor');
    $user->forceFill(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id])->save();
    $submitted = app(LoanService::class)->submit($user->fresh(), $loan);

    expect($submitted->borrower_status)->toBe('STUDENT')->and($submitted->advisor_id)->toBe($advisor->id)->and($submitted->status)->toBe('SUBMITTED');
});

test('submit refuses a borrower who moved branch, was deactivated, or lost the advisor since the draft was made; the draft stays a draft', function (string $change) {
    [$lab, $asset] = loanBranch();
    $user = studentUser(['lab_id' => $lab->id]);
    $loan = draftLoan($user, $asset, 1);

    // A deactivated borrower acting for themself is stopped by the owner check first (not_allowed).
    $expected = match ($change) {
        'moved to another branch' => tap(__('loans.error.lab_changed'), fn () => $user->update(['lab_id' => makeLab()->id])),
        'deactivated' => tap(__('loans.error.not_allowed'), fn () => $user->update(['is_active' => false])),
        'advisor removed' => tap(__('loans.error.advisor_missing'), fn () => $user->update(['advisor_id' => null])),
        'branch removed' => tap(__('loans.error.borrower_no_lab'), fn () => $user->update(['lab_id' => null])),
    };

    expect(fn () => app(LoanService::class)->submit($user->fresh(), $loan))->toThrow(LoanDomainException::class, $expected);
    expect($loan->fresh()->status)->toBe('DRAFT')->and($loan->fresh()->submitted_at)->toBeNull();
})->with(['moved to another branch', 'deactivated', 'advisor removed', 'branch removed']);

// --- BR-L05 and the lines -------------------------------------------------------------------------------------------

test('BR-L05: a line must be an asset of the BORROWER\'s branch and still ACTIVE; another branch\'s, a retired and an unknown asset are refused and nothing is created', function () {
    [$labA, $assetA] = loanBranch();
    [, $assetB] = loanBranch();
    $staff = staffUser(['lab_id' => $labA->id]);

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$assetB->ulid => '1']))->assertSessionHasErrors(['loan' => __('loans.error.asset_other_lab')]);
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$assetA->ulid => '1', $assetB->ulid => '1']))->assertSessionHasErrors(['loan' => __('loans.error.asset_other_lab')]);
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([(string) Str::ulid() => '1']))->assertSessionHasErrors(['loan' => __('loans.error.asset_not_lendable')]);
    DB::table('loan_assets')->where('id', $assetA->id)->update(['status' => 'RETIRED']);
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$assetA->ulid => '1']))->assertSessionHasErrors(['loan' => __('loans.error.asset_not_lendable')]);

    expect(Loan::count())->toBe(0);
    expect(LoanLine::count())->toBe(0);
});

test('quantities are whole numbers; blank and 0 rows are ignored; with nothing left the request is refused', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);

    foreach (['1.5', 'abc', '-1', '1e2'] as $bad) {
        $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => $bad]))->assertSessionHasErrors("qty.{$asset->ulid}");
    }
    foreach (['', '0'] as $nothing) {
        $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => $nothing]))->assertSessionHasErrors(['loan' => __('loans.error.no_lines')]);
    }
    expect(Loan::count())->toBe(0);
});

test('BR-L02 (first point): asking for more than is available is refused at submit and the draft is kept; a UNIT asset is exactly 1', function () {
    [$lab, $asset] = loanBranch(10);
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $staff = staffUser(['lab_id' => $lab->id]);
    $name = (string) $asset->load('item')->item?->name_th;

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '11'], ['action' => 'submit']))
        ->assertSessionHasErrors(['loan' => __('loans.error.not_enough_available', ['item' => $name])]);
    expect(Loan::count())->toBe(1);
    expect(Loan::firstOrFail()->status)->toBe('DRAFT');

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$unit->ulid => '2']))->assertSessionHasErrors(['loan' => __('loans.error.unit_qty_one')]);
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '10', $unit->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();
    expect(Loan::where('status', 'SUBMITTED')->count())->toBe(1);
});

test('BR-L06: pick-up not in the past nor too far ahead, return not before pick-up, and not longer than 30 days or the asset\'s own limit', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);
    $post = fn (array $dates) => $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], $dates));

    $post(loanDates(3, -1))->assertSessionHasErrors(['loan' => __('loans.error.loan_date_past')]);
    $post(loanDates(3, 31))->assertSessionHasErrors(['loan' => __('loans.error.loan_date_too_far', ['days' => 30])]);
    $post(['loan_date' => loanDates()['due_date'], 'due_date' => loanDates()['loan_date']])->assertSessionHasErrors('due_date');
    $post(loanDates(31))->assertSessionHasErrors(['loan' => __('loans.error.period_too_long', ['days' => 30])]);
    expect(Loan::count())->toBe(0);

    $post(loanDates(30, 30))->assertSessionHasNoErrors();   // exactly at both limits
    expect(Loan::count())->toBe(1);

    // An asset can only SHORTEN the limit.
    DB::table('loan_assets')->where('id', $asset->id)->update(['max_loan_days' => 3]);
    $post(loanDates(5))->assertSessionHasErrors(['loan' => __('loans.error.period_too_long', ['days' => 3])]);
    $post(loanDates(3))->assertSessionHasNoErrors();
    expect(Loan::count())->toBe(2);
});

test('the purpose is required, and "other" needs a detail', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['purpose_type' => 'NONSENSE']))->assertSessionHasErrors('purpose_type');
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['purpose_type' => 'OTHER']))->assertSessionHasErrors('purpose_detail');
    $this->actingAs($staff)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['purpose_type' => 'OTHER', 'purpose_detail' => 'ถ่ายทำสื่อการสอน']))->assertSessionHasNoErrors();
    expect(Loan::count())->toBe(1);
});

test('the request form offers only the borrower\'s branch\'s assets that have something free', function () {
    [$lab] = loanBranch();
    [$otherLab] = loanBranch();
    loanExtraAsset($lab, 5, makeCountItem(['name_th' => 'ZITEMFREE']));
    $full = loanExtraAsset($lab, 5, makeCountItem(['name_th' => 'ZITEMFULL']));
    loanExtraAsset($otherLab, 5, makeCountItem(['name_th' => 'ZITEMOTHER']));
    DB::table('loan_assets')->where('id', $full->id)->update(['qty_on_loan' => 5]);
    $retired = loanExtraAsset($lab, 5, makeCountItem(['name_th' => 'ZITEMRETIRED']));
    DB::table('loan_assets')->where('id', $retired->id)->update(['status' => 'RETIRED']);
    $staff = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($staff)->get(route('loans.create'))->assertOk()
        ->assertSee('ZITEMFREE')->assertDontSee('ZITEMFULL')->assertDontSee('ZITEMOTHER')->assertDontSee('ZITEMRETIRED');
});

// --- document numbers (BR-L16) ----------------------------------------------------------------------------------------

test('BR-L16: numbers are LON-{พ.ศ. fiscal year}-{5 digits}, running per fiscal year that starts on 1 October', function () {
    [$lab, $asset] = loanBranch();
    $staff = staffUser(['lab_id' => $lab->id]);

    $this->travelTo(Carbon::create(2026, 9, 30, 12, 0, 0));
    expect(draftLoan($staff, $asset, 1)->doc_no)->toBe('LON-2569-00001');
    expect(draftLoan($staff, $asset, 1)->doc_no)->toBe('LON-2569-00002');

    $this->travelTo(Carbon::create(2026, 10, 1, 0, 5, 0));
    expect(draftLoan($staff, $asset, 1)->doc_no)->toBe('LON-2570-00001');
    expect(draftLoan($staff, $asset, 1)->doc_no)->toBe('LON-2570-00002');
});

// --- who may open a document (IDOR) -------------------------------------------------------------------------------------

test('IDOR: somebody else\'s document is 403 on every route — page, edit, update, submit, cancel and both decisions — and an unknown or numeric id is 404', function () {
    [$lab, $asset] = loanBranch();
    $owner = staffUser(['lab_id' => $lab->id]);
    $intruder = staffUser(['lab_id' => $lab->id]);
    $draft = draftLoan($owner, $asset, 1);
    $submitted = submittedLoan($owner, $asset, 1);

    foreach ([$draft, $submitted] as $loan) {
        $this->actingAs($intruder)->get(route('loans.show', $loan))->assertForbidden();
        $this->actingAs($intruder)->get(route('loans.edit', $loan))->assertForbidden();
        $this->actingAs($intruder)->put(route('loans.update', $loan), loanPayload([$asset->ulid => '1']))->assertForbidden();
        $this->actingAs($intruder)->post(route('loans.submit', $loan))->assertForbidden();
        loanCancelPost($this, $intruder, $loan, ['reason' => 'ลองยกเลิกของคนอื่น'])->assertForbidden();
        loanAdvisorPost($this, $intruder, $loan)->assertForbidden();
        loanManagerPost($this, $intruder, $loan)->assertForbidden();
    }
    expect($draft->fresh()->status)->toBe('DRAFT')->and($submitted->fresh()->status)->toBe('SUBMITTED');

    $this->actingAs($owner)->get('/loans/'.Str::ulid())->assertNotFound();
    $this->actingAs($owner)->get('/loans/'.$submitted->id)->assertNotFound();
    loanCancelPost($this, $owner, $submitted)->assertRedirect();   // the real owner is fine
});

test('who may open a document: the owners always; the advisor, the branch\'s people and ADMIN only once it is submitted, never somebody\'s draft; other branches never', function () {
    [$lab, $asset] = loanBranch();
    [$otherLab] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $draft = draftLoan($student, $asset, 1);
    $submitted = submittedLoan($student, $asset, 1);
    $advisor = User::findOrFail($student->advisor_id);

    $see = fn (User $user, Loan $loan) => $this->actingAs($user)->get(route('loans.show', $loan))->getStatusCode();

    // The student and the advisor named on the document.
    expect($see($student, $draft))->toBe(200)->and($see($student, $submitted))->toBe(200);
    expect($see($advisor, $draft))->toBe(403)->and($see($advisor, $submitted))->toBe(200);
    expect($see(navUser('advisor'), $submitted))->toBe(403);

    // The branch's people (scientist, warehouse manager) and ADMIN.
    foreach ([scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), adminUser(['lab_id' => null])] as $viewer) {
        expect($see($viewer, $draft))->toBe(403)->and($see($viewer, $submitted))->toBe(200);
    }

    // Another branch's scientist and manager, a scientist with no branch, a colleague who borrows.
    foreach ([scientistUser(['lab_id' => $otherLab->id]), auditorUser(['lab_id' => $otherLab->id]), scientistUser(['lab_id' => null]), staffUser(['lab_id' => $lab->id])] as $viewer) {
        expect($see($viewer, $draft))->toBe(403)->and($see($viewer, $submitted))->toBe(403);
    }
});

// --- cancel (FR-LN-16) --------------------------------------------------------------------------------------------------

test('the borrower cancels their own request before hand-out, with or without a reason, and it is recorded; nothing is reserved so nothing is undone', function (string $stage) {
    [$lab, $asset] = loanBranch();
    $student = studentUser(['lab_id' => $lab->id]);
    $loan = match ($stage) {
        'DRAFT' => draftLoan($student, $asset, 2),
        'SUBMITTED' => submittedLoan($student, $asset, 2),
        'ADVISOR_APPROVED' => forceLoanStatus(submittedLoan($student, $asset, 2), 'ADVISOR_APPROVED'),
        'APPROVED' => forceLoanStatus(submittedLoan($student, $asset, 2), 'APPROVED'),
    };

    loanCancelPost($this, $student, $loan)->assertRedirect(route('loans.show', $loan));

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('CANCELLED')->and($fresh->cancelled_by)->toBe($student->id)->and($fresh->cancelled_at)->not->toBeNull()->and($fresh->cancel_reason)->toBeNull();
    $audit = loanAudit('LOAN_CANCEL', $loan);
    expect($audit)->toHaveCount(1);
    expect($audit[0]->old_value['status'])->toBe($stage)->and($audit[0]->new_value['status'])->toBe('CANCELLED')->and($audit[0]->new_value['by_borrower'])->toBeTrue();
    expect($asset->fresh()->qty_on_loan)->toBe('0.000000');
})->with(['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED']);

test('the person who typed the request in may cancel it; a reason, when given, is stored', function () {
    [$lab, $asset] = loanBranch();
    $counter = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1, $counter);

    loanCancelPost($this, $counter, $loan, ['reason' => '  ผู้ยืมเปลี่ยนแผน  '])->assertRedirect();

    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('CANCELLED')->and($fresh->cancelled_by)->toBe($counter->id)->and($fresh->cancel_reason)->toBe('ผู้ยืมเปลี่ยนแผน');
    expect(loanAudit('LOAN_CANCEL', $loan)[0]->new_value['by_borrower'])->toBeFalse();
});

test('a branch approver cancels somebody else\'s submitted request only with a reason of at least 5 characters; not their DRAFT', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($borrower, $asset, 1);
    $draft = draftLoan($borrower, $asset, 1);

    loanCancelPost($this, $scientist, $draft, ['reason' => 'ขอยกเลิกฉบับร่างของคนอื่น'])->assertForbidden();

    foreach ([[], ['reason' => ''], ['reason' => 'ก'], ['reason' => '    ']] as $payload) {
        loanCancelPost($this, $scientist, $loan, $payload)->assertSessionHasErrors(['loan' => __('loans.error.cancel_reason_required')]);
    }
    expect($loan->fresh()->status)->toBe('SUBMITTED');

    loanCancelPost($this, $scientist, $loan, ['reason' => 'ของไม่พร้อมใช้งาน'])->assertRedirect(route('loans.show', $loan));
    $fresh = $loan->fresh();
    expect($fresh->status)->toBe('CANCELLED')->and($fresh->cancelled_by)->toBe($scientist->id)->and($fresh->cancel_reason)->toBe('ของไม่พร้อมใช้งาน');
    expect(loanAudit('LOAN_CANCEL', $loan)[0]->new_value['by_borrower'])->toBeFalse();
});

test('nobody else cancels: an approver of another branch, one with no branch, and an ADMIN (cancels only their own) get 403', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    foreach ([
        scientistUser(['lab_id' => makeLab()->id]), auditorUser(['lab_id' => makeLab()->id]), scientistUser(['lab_id' => null]),
        adminUser(['lab_id' => $lab->id]), adminUser(['lab_id' => null]),
    ] as $user) {
        loanCancelPost($this, $user, $loan, ['reason' => 'ลองยกเลิกโดยผู้ไม่เกี่ยวข้อง'])->assertForbidden();
    }
    expect($loan->fresh()->status)->toBe('SUBMITTED');

    // ADMIN's own request is theirs to cancel.
    $admin = adminUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $mine = submittedLoan($admin, $asset, 1);
    loanCancelPost($this, $admin, $mine)->assertRedirect();
    expect($mine->fresh()->status)->toBe('CANCELLED');
});

test('after hand-out, or once the document is over, nobody can cancel it: page 403 and the service refuses', function (string $status) {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = forceLoanStatus(submittedLoan($borrower, $asset, 1), $status);

    foreach ([$borrower, scientistUser(['lab_id' => $lab->id]), adminUser(['lab_id' => $lab->id])] as $user) {
        loanCancelPost($this, $user, $loan, ['reason' => 'ลองยกเลิกหลังส่งมอบ'])->assertForbidden();
    }
    expect(fn () => app(LoanService::class)->cancel($borrower->fresh(), $loan, 'ลองยกเลิกหลังส่งมอบ'))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect($loan->fresh()->status)->toBe($status)->and($loan->fresh()->cancelled_at)->toBeNull();
})->with(['ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED']);

// --- lists, queue, branch ----------------------------------------------------------------------------------------------

test('"things I borrowed" lists what I borrowed or what was typed in for me, and nobody else\'s', function () {
    [$lab, $asset] = loanBranch();
    $alice = staffUser(['lab_id' => $lab->id]);
    $bob = staffUser(['lab_id' => $lab->id]);
    $counter = scientistUser(['lab_id' => $lab->id]);
    $a = draftLoan($alice, $asset, 1);
    $b = draftLoan($bob, $asset, 1);
    $c = draftLoan($bob, $asset, 1, $counter);   // typed in for bob by the counter

    $page = fn (User $user) => $this->actingAs($user)->get(route('loans.mine'))->assertOk();

    $page($alice)->assertSee($a->doc_no)->assertDontSee($b->doc_no)->assertDontSee($c->doc_no);
    $page($bob)->assertSee($b->doc_no)->assertSee($c->doc_no)->assertDontSee($a->doc_no);
    $page($counter)->assertSee($c->doc_no)->assertDontSee($a->doc_no)->assertDontSee($b->doc_no);

    // The status filter narrows it; the number filter finds one.
    $this->actingAs($bob)->get(route('loans.mine', ['status' => 'SUBMITTED']))->assertOk()->assertDontSee($b->doc_no);
    $this->actingAs($bob)->get(route('loans.mine', ['q' => $c->doc_no]))->assertOk()->assertSee($c->doc_no)->assertDontSee($b->doc_no);
});

test('the approval queue (managers): own branch, waiting for a manager only, not what I typed in for somebody else', function () {
    [$lab, $asset] = loanBranch(30);
    [$labB, $assetB] = loanBranch();
    $s = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $s2 = scientistUser(['lab_id' => $lab->id]);

    $staffSubmitted = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $studentWaitingAdvisor = submittedLoan(studentUser(['lab_id' => $lab->id]), $asset, 1);
    $studentAdvisorDone = forceLoanStatus(submittedLoan(studentUser(['lab_id' => $lab->id]), $asset, 1), 'ADVISOR_APPROVED', ['advisor_signed_at' => now()]);
    $approved = forceLoanStatus(submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1), 'APPROVED');
    $draft = draftLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $otherBranch = submittedLoan(staffUser(['lab_id' => $labB->id]), $assetB, 1);
    $typedInByS = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1, $s);
    $mineOwn = submittedLoan($s, $asset, 1);

    $s1Page = $this->actingAs($s)->get(route('loans.queue'))->assertOk();
    $s1Page->assertSee($staffSubmitted->doc_no)->assertSee($studentAdvisorDone->doc_no)->assertSee($mineOwn->doc_no);
    foreach ([$studentWaitingAdvisor, $approved, $draft, $otherBranch, $typedInByS] as $not) {
        $s1Page->assertDontSee($not->doc_no);
    }

    // The colleague sees what S typed in (S may not approve it, the colleague may).
    $this->actingAs($s2)->get(route('loans.queue'))->assertOk()->assertSee($typedInByS->doc_no)->assertSee($staffSubmitted->doc_no)->assertDontSee($otherBranch->doc_no);
});

test('the manager queue tells approvers that a student\'s loan does not pass this step (queue_manager_note); an advisor-only user does not get the manager section or the note', function () {
    [$lab, $asset] = loanBranch();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    $this->actingAs($scientist)->get(route('loans.queue'))->assertOk()
        ->assertSee($loan->doc_no)
        ->assertSee(__('loans.queue_manager_note'));

    $this->actingAs(navUser('advisor'))->get(route('loans.queue'))->assertOk()
        ->assertDontSee(__('loans.queue_manager_note'));
});

test('the approval queue (advisors): only their own advisees\' requests that wait for them; the manager section is not theirs', function () {
    [$lab, $asset] = loanBranch(10);
    $student = studentUser(['lab_id' => $lab->id]);
    $advisor = User::findOrFail($student->advisor_id);
    $waiting = submittedLoan($student, $asset, 1);
    $done = forceLoanStatus(submittedLoan($student, $asset, 1), 'ADVISOR_APPROVED', ['advisor_signed_at' => now()]);
    $stranger = submittedLoan(studentUser(['lab_id' => $lab->id]), $asset, 1);

    $this->actingAs($advisor)->get(route('loans.queue'))->assertOk()
        ->assertSee($waiting->doc_no)->assertDontSee($done->doc_no)->assertDontSee($stranger->doc_no);
    $this->actingAs(navUser('advisor'))->get(route('loans.queue'))->assertOk()->assertDontSee($waiting->doc_no)->assertDontSee($stranger->doc_no);
});

test('the queue page is for advisors and branch approvers only: borrowers, ADMIN and an approver with no branch get 403', function () {
    $lab = makeLab();

    foreach ([staffUser(['lab_id' => $lab->id]), studentUser(['lab_id' => $lab->id]), adminUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => null])] as $user) {
        $this->actingAs($user)->get(route('loans.queue'))->assertForbidden();
    }
    foreach ([scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => $lab->id]), navUser('advisor')] as $user) {
        $this->actingAs($user)->get(route('loans.queue'))->assertOk();
    }
});

test('the branch list (FR-LN-31): a manager sees only their branch and no drafts, cannot widen it with ?lab_id=, and the filters work; ADMIN sees every branch', function () {
    [$labA, $assetA] = loanBranch(30);
    [$labB, $assetB] = loanBranch();
    $manager = auditorUser(['lab_id' => $labA->id]);
    $other = staffUser(['lab_id' => $labA->id, 'full_name' => 'ZZ ผู้ยืมอีกคน']);
    $named = staffUser(['lab_id' => $labA->id, 'full_name' => 'ZZ ผู้ยืมชื่อพิเศษ']);
    $special = makeCountItem(['name_th' => 'ZITEMSPECIAL']);
    $specialAsset = loanExtraAsset($labA, 5, $special);

    $inA = submittedLoan($other, $assetA, 1);
    $named1 = submittedLoan($named, $specialAsset, 1);
    $approvedA = forceLoanStatus(submittedLoan($other, $assetA, 1), 'APPROVED');
    $draftA = draftLoan($other, $assetA, 1);
    $inB = submittedLoan(staffUser(['lab_id' => $labB->id]), $assetB, 1);

    $page = fn (User $user, array $query = []) => $this->actingAs($user)->get(route('loans.branch', $query))->assertOk();

    $page($manager)->assertSee($inA->doc_no)->assertSee($named1->doc_no)->assertSee($approvedA->doc_no)->assertDontSee($draftA->doc_no)->assertDontSee($inB->doc_no)->assertDontSee($labB->code);
    $page($manager, ['lab_id' => $labB->id])->assertSee($inA->doc_no)->assertDontSee($inB->doc_no);   // the branch cannot be widened

    $page($manager, ['status' => 'APPROVED'])->assertSee($approvedA->doc_no)->assertDontSee($inA->doc_no);
    $page($manager, ['q' => $named1->doc_no])->assertSee($named1->doc_no)->assertDontSee($inA->doc_no);
    $page($manager, ['borrower' => 'ผู้ยืมชื่อพิเศษ'])->assertSee($named1->doc_no)->assertDontSee($inA->doc_no);
    $page($manager, ['item' => 'ZITEMSPECIAL'])->assertSee($named1->doc_no)->assertDontSee($inA->doc_no);
    $page($manager, ['from' => now()->addDays(2)->format('Y-m-d')])->assertDontSee($inA->doc_no);

    $admin = adminUser(['lab_id' => null]);
    $page($admin)->assertSee($inA->doc_no)->assertSee($inB->doc_no)->assertDontSee($draftA->doc_no);
    $page($admin, ['lab_id' => $labB->id])->assertSee($inB->doc_no)->assertDontSee($inA->doc_no);
});

test('the branch list is for people who hold loan.view_all with a branch (ADMIN needs none): borrowers, advisors, scientists and a manager with no branch get 403', function () {
    $lab = makeLab();

    foreach ([staffUser(['lab_id' => $lab->id]), studentUser(['lab_id' => $lab->id]), navUser('advisor', ['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id]), auditorUser(['lab_id' => null])] as $user) {
        $this->actingAs($user)->get(route('loans.branch'))->assertForbidden();
    }
    foreach ([auditorUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id]), adminUser(['lab_id' => null])] as $user) {
        $this->actingAs($user)->get(route('loans.branch'))->assertOk();
    }
});

test('no list, page or document of the loan module shows a replacement value or another branch\'s data to a borrower', function () {
    [$lab, $asset] = loanBranch();
    DB::table('loan_assets')->where('id', $asset->id)->update(['replacement_value' => '98765.43']);
    $staff = staffUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($staff, $asset, 1);

    foreach ([route('loans.create'), route('loans.mine'), route('loans.show', $loan)] as $url) {
        $this->actingAs($staff)->get($url)->assertOk()->assertDontSee('98765');
    }
    $this->actingAs(scientistUser(['lab_id' => $lab->id]))->get(route('loans.show', $loan))->assertOk()->assertDontSee('98765');
    expect(LoanApproval::count())->toBe(0);
    expect(LoanAsset::count())->toBe(1);
});
