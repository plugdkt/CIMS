<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\BorrowerResolver;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanService;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 3 (WP-3.6, BR-L08): a borrower with a loan overdue by MORE than `loans.overdue_block_days` (7)
 * gets no new request — neither a new draft nor the submission of an old one — until the things are back.
 *
 * The one exception, per request: an approver of the borrower's branch (`loan.approve`, same branch) who is NOT the
 * borrower types the request in for them and writes why (at least 10 characters). It is recorded as
 * LOAN_OVERDUE_OVERRIDE and stored nowhere else, so the next request is blocked again.
 *
 * "Overdue" is computed from the status and the due date; the tests move the clock instead of editing rows.
 */

beforeEach(function () {
    Mail::fake();
});

const OVERRIDE_REASON = 'จำเป็นต้องใช้สอนพรุ่งนี้';   // >= 10 characters

/**
 * A branch, its asset, a STAFF borrower whose loan is out, and the clock moved to `$daysOverdue` days past its due date.
 *
 * @return array{\App\Models\Lab, \App\Models\LoanAsset, \App\Models\User, \App\Models\Loan}
 */
function overdueScene($test, int $daysOverdue): array
{
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2);
    $test->travelTo($loan->due_date->copy()->addDays($daysOverdue)->setTime(10, 0));

    return [$lab, $asset, $borrower, $loan];
}

/** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditLog> */
function overrideAudit(): \Illuminate\Database\Eloquent\Collection
{
    return AuditLog::where('action', 'LOAN_OVERDUE_OVERRIDE')->orderBy('id')->get();
}

function blockedMessage(Loan ...$loans): string
{
    return (string) __('loans.error.overdue_blocked', ['days' => 7, 'docs' => implode(', ', array_map(fn (Loan $l) => $l->doc_no, $loans))]);
}

// --- the edge: 7 days is still fine, 8 is blocked -----------------------------------------------------------------------

test('the shipped threshold is 7 days (config/loans.php)', function () {
    expect(config('loans.overdue_block_days'))->toBe(7);
});

test('overdue by exactly 7 days: the borrower can still make and submit a new request', function () {
    [, $asset, $borrower, $late] = overdueScene($this, 7);
    expect($late->fresh()->daysOverdue())->toBe(7);
    expect(app(LoanService::class)->blockingOverdueLoans($borrower->id))->toBe([]);

    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();

    $new = Loan::where('borrower_id', $borrower->id)->where('id', '!=', $late->id)->firstOrFail();
    expect($new->status)->toBe('SUBMITTED');
    expect(overrideAudit())->toHaveCount(0);
});

test('overdue by 8 days: no new draft, by the page or by the service, and the message names the late document', function () {
    [, $asset, $borrower, $late] = overdueScene($this, 8);
    expect($late->fresh()->daysOverdue())->toBe(8);
    expect(app(LoanService::class)->blockingOverdueLoans($borrower->id))->toBe([$late->doc_no]);

    foreach ([[], ['action' => 'submit'], ['overdue_override_reason' => OVERRIDE_REASON]] as $extra) {
        $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], $extra))
            ->assertSessionHasErrors(['loan' => blockedMessage($late)]);
    }
    expect(fn () => draftLoan($borrower, $asset, 1))->toThrow(LoanDomainException::class, blockedMessage($late));

    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(1);
    expect(overrideAudit())->toHaveCount(0);

    // The form says why before anything is typed, and offers the borrower no way round.
    $this->actingAs($borrower)->get(route('loans.create'))->assertOk()
        ->assertSee(__('loans.overdue_block_title'))->assertSee($late->doc_no)
        ->assertDontSee('name="overdue_override_reason"', false);
});

test('a draft made before the borrower fell behind cannot be submitted once they are blocked', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $late = handedOutLoan($borrower, $asset, 1);
    // A long-running draft: pick-up far enough ahead to still be a valid period when it is finally submitted.
    $draft = draftLoan($borrower, $asset, 1, null, loanDates(7, 20));

    $this->travelTo($late->due_date->copy()->addDays(8)->setTime(10, 0));

    $this->actingAs($borrower)->post(route('loans.submit', $draft))
        ->assertRedirect(route('loans.show', $draft))
        ->assertSessionHasErrors(['loan' => blockedMessage($late)]);
    expect(fn () => app(LoanService::class)->submit($borrower->fresh(), $draft))->toThrow(LoanDomainException::class, blockedMessage($late));
    // Saving the draft's form with "submit" does not get through either.
    $this->actingAs($borrower)->put(route('loans.update', $draft), loanPayload([$asset->ulid => '1'], array_merge(loanDates(7, 3), ['action' => 'submit'])))
        ->assertSessionHasErrors('loan');

    expect($draft->fresh()->status)->toBe('DRAFT')->and($draft->fresh()->submitted_at)->toBeNull();
    expect(loanAudit('LOAN_SUBMIT', $draft))->toHaveCount(0);

    // The draft's page carries the notice and, for the borrower, no submit button at all.
    $this->actingAs($borrower)->get(route('loans.show', $draft))->assertOk()
        ->assertSee(__('loans.overdue_block_title'))->assertSee($late->doc_no)
        ->assertDontSee(route('loans.submit', $draft), false);
});

test('the block lifts when the things come back; a partial return is not enough; other borrowers are never affected', function () {
    [$lab, $asset, $borrower, $late] = overdueScene($this, 9);
    $handler = scientistUser(['lab_id' => $lab->id]);
    $service = app(LoanService::class);
    $neighbour = staffUser(['lab_id' => $lab->id]);

    // Somebody else of the same branch borrows as usual.
    $this->actingAs($neighbour)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();
    expect(Loan::where('borrower_id', $neighbour->id)->firstOrFail()->status)->toBe('SUBMITTED');

    app(LoanCirculationService::class)->receiveReturn($handler, $late, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    expect($late->fresh()->status)->toBe('PARTIALLY_RETURNED');
    expect($service->blockingOverdueLoans($borrower->id))->toBe([$late->doc_no]);
    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertSessionHasErrors('loan');

    app(LoanCirculationService::class)->receiveReturn($handler, $late, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    expect($late->fresh()->status)->toBe('CLOSED');
    expect($service->blockingOverdueLoans($borrower->id))->toBe([]);
    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['action' => 'submit']))->assertSessionHasNoErrors();
    expect(Loan::where('borrower_id', $borrower->id)->where('status', 'SUBMITTED')->count())->toBe(1);
});

test('every long-overdue loan is named, oldest due date first; a loan overdue by less does not block and is not named', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $second = handedOutLoan($borrower, $asset, 1, null, loanDates(5));    // due on day 5
    $first = handedOutLoan($borrower, $asset, 1, null, loanDates(3));     // due on day 3
    $recent = handedOutLoan($borrower, $asset, 1, null, loanDates(10));   // due on day 10

    $this->travelTo(now()->startOfDay()->addDays(14)->setTime(10, 0));     // 11, 9 and 4 days overdue

    expect(app(LoanService::class)->blockingOverdueLoans($borrower->id))->toBe([$first->doc_no, $second->doc_no]);
    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))
        ->assertSessionHasErrors(['loan' => blockedMessage($first, $second)]);
    expect($recent->fresh()->isOverdue())->toBeTrue();
});

// --- the exception, per request ---------------------------------------------------------------------------------------------

test('an approver of the borrower\'s branch types the request in, writes why, and it goes through — recorded at the draft and at the submission', function (string $kind) {
    [$lab, $asset, $borrower, $late] = overdueScene($this, 8);
    $clerk = navUser($kind, ['lab_id' => $lab->id]);

    // The form shows the block and, for this person, the reason box.
    $this->actingAs($clerk)->get(route('loans.create', ['borrower' => $borrower->ulid]))->assertOk()
        ->assertSee(__('loans.overdue_block_title'))->assertSee($late->doc_no)
        ->assertSee('name="overdue_override_reason"', false);

    $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], [
        'borrower' => $borrower->ulid, 'overdue_override_reason' => OVERRIDE_REASON, 'action' => 'submit',
    ]))->assertSessionHasNoErrors();

    $new = Loan::where('borrower_id', $borrower->id)->where('id', '!=', $late->id)->firstOrFail();
    expect($new->status)->toBe('SUBMITTED')->and($new->created_by)->toBe($clerk->id);

    $audit = overrideAudit();
    expect($audit)->toHaveCount(2);
    expect($audit->pluck('new_value.at')->all())->toBe(['create', 'submit']);
    foreach ($audit as $row) {
        expect($row->user_id)->toBe($clerk->id)->and($row->entity_type)->toBe(Loan::class)->and($row->entity_id)->toBe($new->id);
        expect($row->new_value['allowed_by'])->toBe($clerk->id)->and($row->new_value['borrower_id'])->toBe($borrower->id);
        expect($row->new_value['overdue_loans'])->toBe([$late->doc_no])->and($row->new_value['reason'])->toBe(OVERRIDE_REASON);
        expect($row->new_value['doc_no'])->toBe($new->doc_no)->and($row->new_value['lab_id'])->toBe($lab->id);
    }
})->with(['scientist', 'auditor', 'lab_manager', 'admin+scientist']);

test('the reason must be at least 10 characters: 9 is refused, 10 accepted, blanks do not count', function () {
    [$lab, $asset, $borrower, $late] = overdueScene($this, 8);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    $message = (string) __('loans.error.overdue_override_reason', ['days' => 7, 'docs' => $late->doc_no]);
    $post = fn (?string $reason) => $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'overdue_override_reason' => $reason]));

    foreach ([null, '', '          ', 'กขคงจฉชซฌ', '123456789', '  12345678   '] as $short) {
        expect(mb_strlen(trim((string) $short)))->toBeLessThan(10);
        $post($short)->assertSessionHasErrors(['loan' => $message]);
    }
    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(1);
    expect(overrideAudit())->toHaveCount(0);

    expect(mb_strlen('กขคงจฉชซฌญ'))->toBe(10);          // 10 Thai characters = 30 bytes: counted as characters
    $post('กขคงจฉชซฌญ')->assertSessionHasNoErrors();
    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(2);
    expect(overrideAudit())->toHaveCount(1);
});

test('the permission is for ONE request: the next one is blocked again, for the clerk without a new reason and for the borrower always', function () {
    [$lab, $asset, $borrower, $late] = overdueScene($this, 8);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    $onBehalf = fn (array $extra) => $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], array_merge(['borrower' => $borrower->ulid], $extra)));

    $onBehalf(['overdue_override_reason' => OVERRIDE_REASON, 'action' => 'submit'])->assertSessionHasNoErrors();
    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(2);

    // Nothing was stored that lets a second request through.
    $onBehalf([])->assertSessionHasErrors('loan');
    $this->actingAs($borrower)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertSessionHasErrors(['loan' => blockedMessage($late)]);
    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(2);
    expect(overrideAudit())->toHaveCount(2);   // create + submit of the one allowed request
});

test('a draft allowed by the clerk does not let the BORROWER submit it: the submission needs the clerk and the reason again', function () {
    [$lab, $asset, $borrower, $late] = overdueScene($this, 8);
    $clerk = scientistUser(['lab_id' => $lab->id]);

    $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'overdue_override_reason' => OVERRIDE_REASON]))
        ->assertSessionHasNoErrors();
    $draft = Loan::where('borrower_id', $borrower->id)->where('status', 'DRAFT')->firstOrFail();
    expect(overrideAudit())->toHaveCount(1);

    // The borrower: blocked, with or without words in the reason field.
    $this->actingAs($borrower)->post(route('loans.submit', $draft), ['overdue_override_reason' => OVERRIDE_REASON])
        ->assertSessionHasErrors(['loan' => blockedMessage($late)]);
    // The clerk without a reason: asked for one.
    $this->actingAs($clerk)->post(route('loans.submit', $draft))
        ->assertSessionHasErrors(['loan' => __('loans.error.overdue_override_reason', ['days' => 7, 'docs' => $late->doc_no])]);
    expect($draft->fresh()->status)->toBe('DRAFT');
    expect(overrideAudit())->toHaveCount(1);

    // The draft's page: the reason box for the clerk, none for the borrower.
    $this->actingAs($clerk)->get(route('loans.show', $draft))->assertOk()
        ->assertSee(__('loans.overdue_block_title'))->assertSee('name="overdue_override_reason"', false);
    $this->actingAs($borrower)->get(route('loans.show', $draft))->assertOk()
        ->assertSee(__('loans.overdue_block_title'))->assertDontSee('name="overdue_override_reason"', false);

    $this->actingAs($clerk)->post(route('loans.submit', $draft), ['overdue_override_reason' => OVERRIDE_REASON])->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe('SUBMITTED');
    expect(overrideAudit()->pluck('new_value.at')->all())->toBe(['create', 'submit']);
});

// --- who may NOT allow it ---------------------------------------------------------------------------------------------------------

test('the borrower cannot allow their own request, even when they are an approver of the branch themselves', function (string $kind) {
    [$lab, $asset] = loanBranch();
    $me = navUser($kind, ['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $late = handedOutLoan($me, $asset, 1);
    $this->travelTo($late->due_date->copy()->addDays(8)->setTime(10, 0));
    $service = app(LoanService::class);

    expect($service->mayOverrideOverdueBlock($me->fresh(), $me->id, $lab->id))->toBeFalse();

    $this->actingAs($me)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['overdue_override_reason' => OVERRIDE_REASON, 'action' => 'submit']))
        ->assertSessionHasErrors(['loan' => blockedMessage($late)]);
    expect(fn () => $service->createDraft($me->fresh(), app(BorrowerResolver::class)->forSelf($me->fresh()), loanData(), [$asset->ulid => '1'], OVERRIDE_REASON))
        ->toThrow(LoanDomainException::class, blockedMessage($late));

    expect(Loan::where('borrower_id', $me->id)->count())->toBe(1);
    expect(overrideAudit())->toHaveCount(0);
    $this->actingAs($me)->get(route('loans.create'))->assertOk()->assertDontSee('name="overdue_override_reason"', false);
})->with(['scientist', 'auditor', 'admin+scientist']);

test('an ADMIN-only account may type a request in for the borrower but cannot lift the block (it is not an approver)', function () {
    [, $asset, $borrower, $late] = overdueScene($this, 8);
    $admin = adminUser(['lab_id' => $borrower->lab_id]);

    expect(app(LoanService::class)->mayOverrideOverdueBlock($admin->fresh(), $borrower->id, (int) $borrower->lab_id))->toBeFalse();
    $this->actingAs($admin)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'overdue_override_reason' => OVERRIDE_REASON]))
        ->assertSessionHasErrors(['loan' => blockedMessage($late)]);
    $this->actingAs($admin)->get(route('loans.create', ['borrower' => $borrower->ulid]))->assertOk()
        ->assertSee(__('loans.overdue_block_title'))->assertDontSee('name="overdue_override_reason"', false);

    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(1);
    expect(overrideAudit())->toHaveCount(0);
});

test('an approver of ANOTHER branch, one with no branch, and people who are not approvers cannot lift the block', function () {
    [$lab, $asset, $borrower] = overdueScene($this, 8);
    $other = makeLab();
    $service = app(LoanService::class);

    $nobody = [
        'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'manager of another branch' => auditorUser(['lab_id' => $other->id]),
        'scientist with no branch' => scientistUser(['lab_id' => null]),
        'inactive scientist of the branch' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
        'STAFF of the branch' => staffUser(['lab_id' => $lab->id]),
        'ADVISOR of the branch' => navUser('advisor', ['lab_id' => $lab->id]),
    ];
    foreach ($nobody as $who => $user) {
        expect($service->mayOverrideOverdueBlock($user->fresh(), $borrower->id, $lab->id))->toBeFalse($who);
    }
    // No branch on the request side matches nothing either.
    expect($service->mayOverrideOverdueBlock(scientistUser(['lab_id' => null]), $borrower->id, null))->toBeFalse();

    // By the page: another branch's scientist cannot even type a request in for this borrower.
    $this->actingAs($nobody['scientist of another branch'])
        ->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'overdue_override_reason' => OVERRIDE_REASON]))
        ->assertSessionHasErrors(['loan' => __('loans.error.borrower_other_lab')]);
    // A STAFF colleague has no on-behalf form at all.
    $this->actingAs($nobody['STAFF of the branch'])
        ->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'overdue_override_reason' => OVERRIDE_REASON]))
        ->assertForbidden();

    expect(Loan::where('borrower_id', $borrower->id)->count())->toBe(1);
    expect(overrideAudit())->toHaveCount(0);
});

test('a reason sent for a borrower who is NOT blocked is ignored: no override is recorded', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $clerk = scientistUser(['lab_id' => $lab->id]);

    $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], [
        'borrower' => $borrower->ulid, 'overdue_override_reason' => OVERRIDE_REASON, 'action' => 'submit',
    ]))->assertSessionHasNoErrors();

    expect(Loan::where('borrower_id', $borrower->id)->firstOrFail()->status)->toBe('SUBMITTED');
    expect(overrideAudit())->toHaveCount(0);
    $this->actingAs($clerk)->get(route('loans.create', ['borrower' => $borrower->ulid]))->assertOk()
        ->assertDontSee(__('loans.overdue_block_title'))->assertDontSee('name="overdue_override_reason"', false);
});

test('the block looks at the BORROWER\'s loans, not at the loans of the person typing the request in', function () {
    [$lab, $asset] = loanBranch();
    $clerk = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $clerksLate = handedOutLoan($clerk, $asset, 1);
    $this->travelTo($clerksLate->due_date->copy()->addDays(8)->setTime(10, 0));

    // The clerk is blocked for themselves ...
    $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertSessionHasErrors(['loan' => blockedMessage($clerksLate)]);
    // ... but still serves a borrower who is in good standing, with no override involved.
    $this->actingAs($clerk)->post(route('loans.store'), loanPayload([$asset->ulid => '1'], ['borrower' => $borrower->ulid, 'action' => 'submit']))->assertSessionHasNoErrors();

    expect(Loan::where('borrower_id', $borrower->id)->firstOrFail()->status)->toBe('SUBMITTED');
    expect(overrideAudit())->toHaveCount(0);
    expect(User::findOrFail($clerk->id)->lab_id)->toBe($lab->id);
});
