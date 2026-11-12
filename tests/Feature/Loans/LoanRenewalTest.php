<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Domain\Loan\Services\LoanRenewalService;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanDueChange;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 3 (WP-3.6, BR-L07 / FR-LN-15): moving a loan's due date, through the REAL routes and
 * LoanRenewalService. This part of the module had never been exercised before these tests.
 *
 *  - The borrower (or whoever typed the loan in) asks, while things are out and the loan is not overdue.
 *  - The first request of a loan takes effect at once; every later one is PENDING until an approver of the loan's own
 *    branch decides. `loans.due_date` moves only on approval; `original_due_date` and decided rows never change.
 *  - One PENDING request at a time; at most `max_renewals` (3) approved renewals; one step is at most
 *    `default_loan_days` (7); never later than `max_total_days` (60) after the hand-out, or twice the shortest
 *    `max_loan_days` of the assets when that is less.
 *  - The SELF rule (the borrower approving their own renewal) has its own section at the end, like the counter's:
 *    allowed and audited as LOAN_SELF_RENEW_*, waiting for the CEO's confirmation.
 */

beforeEach(function () {
    Mail::fake();
});

function renewPost($test, User $user, Loan $loan, string $newDue, ?string $reason = null, array $extra = [])
{
    return $test->actingAs($user)->post(route('loans.renewals.store', $loan), array_merge(['new_due_date' => $newDue, 'reason' => $reason], $extra));
}

function renewDecidePost($test, User $user, Loan $loan, int|string $seq, string $decision = 'APPROVE', ?string $note = null)
{
    return $test->actingAs($user)->post(route('loans.renewals.decide', ['loan' => $loan, 'seq' => $seq]), ['decision' => $decision, 'note' => $note]);
}

/** The loan's due date moved by `$days`, as the form sends it. */
function dueIn(Loan $loan, int $days): string
{
    return $loan->fresh()->due_date->copy()->addDays($days)->format('Y-m-d');
}

/**
 * Everything a renewal may change, for "nothing was written" checks.
 *
 * @return array<string, mixed>
 */
function renewState(Loan $loan): array
{
    $fresh = $loan->fresh();

    return [
        'due_date' => $fresh->due_date->toDateString(),
        'original_due_date' => $fresh->original_due_date->toDateString(),
        'renew_count' => $fresh->renew_count,
        'changes' => LoanDueChange::where('loan_id', $loan->id)->orderBy('seq')->get()
            ->map(fn ($c) => [$c->seq, $c->status, $c->old_due_date->toDateString(), $c->new_due_date->toDateString(), $c->approved_by, $c->decision_note])->all(),
        'audit' => loanRenewAudit($loan)->count(),
    ];
}

/** @return array{\App\Models\Lab, \App\Models\LoanAsset, \App\Models\User, \App\Models\User, \App\Models\Loan} lab, asset, borrower (STAFF), approver (SCIENTIST), loan ON_LOAN (7 days) */
function renewScene(int $days = 7): array
{
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approver = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset, 2, null, loanDates($days));

    return [$lab, $asset, $borrower, $approver, $loan];
}

/** Moves the due date by `$days` through the real service: asks, and (from the second time on) has `$approver` approve. */
function renewBy(Loan $loan, User $borrower, User $approver, int $days): LoanDueChange
{
    $service = app(LoanRenewalService::class);
    $change = $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, $days), null);
    if ($change->status === 'PENDING') {
        $change = $service->decide($approver->fresh(), $loan->fresh(), $change->seq, 'APPROVE', null);
    }

    return $change;
}

// --- the configuration these tests rely on ----------------------------------------------------------------------------

test('the shipped limits are 3 renewals, 60 days in total, 7 days per step (config/loans.php)', function () {
    expect(config('loans.max_renewals'))->toBe(3);
    expect(config('loans.max_total_days'))->toBe(60);
    expect(config('loans.default_loan_days'))->toBe(7);
});

// --- asking ---------------------------------------------------------------------------------------------------------------

test('the FIRST request takes effect at once: the due date moves, nobody approves, the original date and the history stay readable', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    $oldDue = $loan->due_date->toDateString();
    $newDue = dueIn($loan, 7);

    renewPost($this, $borrower, $loan, $newDue, 'ยังทำการทดลองไม่เสร็จ')
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', __('loans.renew_auto_approved'));

    $fresh = $loan->fresh();
    expect($fresh->due_date->toDateString())->toBe($newDue)->and($fresh->original_due_date->toDateString())->toBe($oldDue);
    expect($fresh->renew_count)->toBe(1)->and($fresh->status)->toBe('ON_LOAN');

    $changes = LoanDueChange::where('loan_id', $loan->id)->get();
    expect($changes)->toHaveCount(1);
    expect($changes[0]->seq)->toBe(1)->and($changes[0]->status)->toBe('APPROVED');
    expect($changes[0]->old_due_date->toDateString())->toBe($oldDue)->and($changes[0]->new_due_date->toDateString())->toBe($newDue);
    expect($changes[0]->requested_by)->toBe($borrower->id)->and($changes[0]->approved_by)->toBeNull()->and($changes[0]->acted_at)->not->toBeNull();
    expect($changes[0]->reason)->toBe('ยังทำการทดลองไม่เสร็จ');

    $audit = loanRenewAudit($loan);
    expect($audit)->toHaveCount(1);
    expect($audit[0]->action)->toBe('LOAN_RENEW_REQUEST')->and($audit[0]->user_id)->toBe($borrower->id);
    expect($audit[0]->old_value['due_date'])->toBe($oldDue);
    expect($audit[0]->new_value['new_due_date'])->toBe($newDue)->and($audit[0]->new_value['auto_approved'])->toBeTrue()->and($audit[0]->new_value['seq'])->toBe(1);

    // An automatic renewal needs nobody: no notice to the approvers.
    expect(Notification::where('user_id', $approver->id)->count())->toBe(0);
    expect(loanNotices(LoanNotifier::TYPE_RENEW_PENDING))->toHaveCount(0);

    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(__('loans.due_auto'))->assertSee(__('loans.field_original_due'))->assertSee(__('loans.renew_later_note'));
});

test('the SECOND request waits: the due date does not move until an approver of the branch approves it', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 7);
    $dueAfterFirst = $loan->fresh()->due_date->toDateString();
    $wanted = dueIn($loan, 5);

    renewPost($this, $borrower, $loan, $wanted, 'ขอเวลาเพิ่ม')
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', __('loans.renew_pending_saved'));

    $fresh = $loan->fresh();
    expect($fresh->due_date->toDateString())->toBe($dueAfterFirst)->and($fresh->renew_count)->toBe(1);
    $pending = LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail();
    expect($pending->status)->toBe('PENDING')->and($pending->approved_by)->toBeNull()->and($pending->acted_at)->toBeNull();
    expect($pending->old_due_date->toDateString())->toBe($dueAfterFirst)->and($pending->new_due_date->toDateString())->toBe($wanted);
    expect(loanRenewAudit($loan)->last()->new_value['auto_approved'])->toBeFalse();

    // Both sides see it waiting; the borrower gets no second form.
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(__('loans.renew_waiting_note', ['due' => $pending->new_due_date->format('d/m/Y')]))
        ->assertDontSee(route('loans.renewals.store', $loan), false);
    $this->actingAs($approver)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(route('loans.renewals.decide', ['loan' => $loan, 'seq' => 2]), false)
        ->assertDontSee(__('loans.self_renew_note'));

    renewDecidePost($this, $approver, $loan, 2, 'APPROVE', 'รับทราบ')
        ->assertRedirect(route('loans.show', $loan))
        ->assertSessionHas('status', __('loans.renew_decision_recorded'));

    $fresh = $loan->fresh();
    expect($fresh->due_date->toDateString())->toBe($wanted)->and($fresh->renew_count)->toBe(2);
    $decided = $pending->fresh();
    expect($decided->status)->toBe('APPROVED')->and($decided->approved_by)->toBe($approver->id)->and($decided->acted_at)->not->toBeNull();
    expect($decided->decision_note)->toBe('รับทราบ');

    $audit = loanRenewAudit($loan)->last();
    expect($audit->action)->toBe('LOAN_RENEW_APPROVE')->and($audit->user_id)->toBe($approver->id);
    expect($audit->old_value['due_date'])->toBe($dueAfterFirst)->and($audit->new_value['due_date'])->toBe($wanted);
    expect($audit->new_value['self_approval'])->toBeFalse()->and($audit->new_value['approver_is_requester'])->toBeFalse()->and($audit->message)->toBeNull();
});

test('a rejection needs a reason, leaves the due date alone, does not count as a renewal, and the borrower may ask again', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 7);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 5), null);
    $before = renewState($loan);

    renewDecidePost($this, $approver, $loan, 2, 'REJECT')->assertSessionHasErrors('note');
    renewDecidePost($this, $approver, $loan, 2, 'REJECT', '   ')->assertSessionHasErrors('note');
    expect(fn () => app(LoanRenewalService::class)->decide($approver->fresh(), $loan->fresh(), 2, 'REJECT', '  '))
        ->toThrow(LoanDomainException::class, __('loans.error.reason_required'));
    renewDecidePost($this, $approver, $loan, 2, 'MAYBE', 'อะไรก็ได้')->assertSessionHasErrors('decision');
    expect(renewState($loan))->toBe($before);

    renewDecidePost($this, $approver, $loan, 2, 'REJECT', 'มีผู้รอใช้เครื่องนี้')->assertRedirect(route('loans.show', $loan))->assertSessionHasNoErrors();

    $fresh = $loan->fresh();
    expect($fresh->due_date->toDateString())->toBe($before['due_date'])->and($fresh->renew_count)->toBe(1);
    $row = LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail();
    expect($row->status)->toBe('REJECTED')->and($row->approved_by)->toBe($approver->id)->and($row->decision_note)->toBe('มีผู้รอใช้เครื่องนี้');
    $audit = loanRenewAudit($loan)->last();
    expect($audit->action)->toBe('LOAN_RENEW_REJECT')->and($audit->new_value['due_date'])->toBe($before['due_date']);

    // Asking again makes seq 3, PENDING (only the very first request of a loan is automatic).
    renewPost($this, $borrower, $loan, dueIn($loan, 3))->assertSessionHasNoErrors();
    $third = LoanDueChange::where('loan_id', $loan->id)->where('seq', 3)->firstOrFail();
    expect($third->status)->toBe('PENDING');
    expect($loan->fresh()->renew_count)->toBe(1);
});

test('only ONE request can wait at a time', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 7);
    renewPost($this, $borrower, $loan, dueIn($loan, 3))->assertSessionHasNoErrors();
    $before = renewState($loan);

    renewPost($this, $borrower, $loan, dueIn($loan, 4))->assertSessionHasErrors(['renew' => __('loans.error.renew_pending_exists')]);
    expect(fn () => app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 4), null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_pending_exists'));

    expect(renewState($loan))->toBe($before);
    expect(LoanDueChange::where('loan_id', $loan->id)->where('status', 'PENDING')->count())->toBe(1);
});

// --- the limits ---------------------------------------------------------------------------------------------------------------

test('a loan is renewed at most 3 times: the 4th request is refused and nothing is written; rejected requests do not use up a turn', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    $service = app(LoanRenewalService::class);

    renewBy($loan, $borrower, $approver, 7);                 // 1 (automatic)
    $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 7), null);
    $service->decide($approver->fresh(), $loan->fresh(), 2, 'REJECT', 'ไม่อนุมัติรอบนี้');   // rejected: not a renewal
    renewBy($loan, $borrower, $approver, 7);                 // 2
    expect($loan->fresh()->renew_count)->toBe(2);
    expect($service->latestPossibleDue($loan->fresh()))->not->toBeNull();
    renewBy($loan, $borrower, $approver, 7);                 // 3
    expect($loan->fresh()->renew_count)->toBe(3);
    $before = renewState($loan);

    renewPost($this, $borrower, $loan, dueIn($loan, 1))->assertSessionHasErrors(['renew' => __('loans.error.renew_limit', ['max' => 3])]);
    expect(fn () => $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 1), null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_limit', ['max' => 3]));

    expect(renewState($loan))->toBe($before);
    expect($service->latestPossibleDue($loan->fresh()))->toBeNull();
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertSee(__('loans.renew_unavailable_note'))->assertDontSee(route('loans.renewals.store', $loan), false);
});

test('a loan takes at most 6 renewal REQUESTS in all, the rejected ones included (config/loans.php max_renewal_requests): the 7th is refused and nothing is written', function () {
    expect(config('loans.max_renewal_requests'))->toBe(6);
    [, , $borrower, $approver, $loan] = renewScene();
    $service = app(LoanRenewalService::class);

    renewBy($loan, $borrower, $approver, 2);                                   // 1: automatic
    foreach (range(2, 6) as $seq) {                                            // 2..6: asked and rejected, one at a time
        renewPost($this, $borrower, $loan, dueIn($loan, 1))->assertSessionHasNoErrors();
        renewDecidePost($this, $approver, $loan, $seq, 'REJECT', 'มีผู้รอใช้ของชิ้นนี้อยู่')->assertSessionHasNoErrors();
    }
    // Only one renewal was used, so the 3-renewals limit is not what stops the next request.
    expect($loan->fresh()->renew_count)->toBe(1);
    expect(LoanDueChange::where('loan_id', $loan->id)->orderBy('seq')->pluck('status')->all())->toBe(['APPROVED', 'REJECTED', 'REJECTED', 'REJECTED', 'REJECTED', 'REJECTED']);
    $before = renewState($loan);

    $message = __('loans.error.renew_request_limit', ['max' => 6]);
    renewPost($this, $borrower, $loan, dueIn($loan, 1))->assertSessionHasErrors(['renew' => $message]);
    expect(fn () => $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 1), null))->toThrow(LoanDomainException::class, $message);

    expect(renewState($loan))->toBe($before);
    expect(LoanDueChange::where('loan_id', $loan->id)->max('seq'))->toBe(6);
});

test('the request limit follows the configuration, counts approved and rejected requests alike, and is per loan', function () {
    config(['loans.max_renewal_requests' => 2]);
    [, $asset, $borrower, $approver, $loan] = renewScene();
    $other = handedOutLoan($borrower, $asset, 1);
    $service = app(LoanRenewalService::class);

    renewBy($loan, $borrower, $approver, 2);     // 1 (automatic)
    renewBy($loan, $borrower, $approver, 2);     // 2 (approved)
    $before = renewState($loan);
    expect(fn () => $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 1), null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_request_limit', ['max' => 2]));
    expect(renewState($loan))->toBe($before);

    // Another loan of the same borrower has its own count.
    expect($service->request($borrower->fresh(), $other->fresh(), dueIn($other, 1), null)->seq)->toBe(1);

    // A nonsense setting never lifts the limit above what `seq` (a TINYINT) can hold, nor below one request.
    config(['loans.max_renewal_requests' => 0]);
    $third = handedOutLoan($borrower, $asset, 1);
    expect($service->request($borrower->fresh(), $third->fresh(), dueIn($third, 1), null)->seq)->toBe(1);
    expect(fn () => $service->request($borrower->fresh(), $third->fresh(), dueIn($third, 1), null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_request_limit', ['max' => 1]));
});

test('step 5 fix: once max_renewal_requests requests exist the renewal form is gone from the loan page (a note says why), while the loan may otherwise still be renewed', function () {
    config(['loans.max_renewal_requests' => 2]);
    [, , $borrower, $approver, $loan] = renewScene();
    $formAction = 'action="'.route('loans.renewals.store', $loan).'"';

    // No request yet: the form is there.
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()
        ->assertSee($formAction, false)->assertDontSee(__('loans.renew_request_limit_note', ['max' => 2]));

    // One request: still there.
    renewBy($loan, $borrower, $approver, 2);
    $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk()->assertSee($formAction, false);

    // Two requests = the ceiling. Only 2 of 3 renewals are used, so it is the REQUEST ceiling that removes the form.
    renewBy($loan, $borrower, $approver, 2);
    expect($loan->fresh()->renew_count)->toBe(2)->and($borrower->fresh()->can('renewLoan', $loan->fresh()))->toBeTrue();
    $page = $this->actingAs($borrower)->get(route('loans.show', $loan))->assertOk();
    $page->assertDontSee($formAction, false)->assertDontSee('name="new_due_date"', false);
    $page->assertSee(__('loans.renew_request_limit_note', ['max' => 2]));

    // The page is only the hint: a direct POST is refused by the service as before, and nothing is written.
    $before = renewState($loan);
    renewPost($this, $borrower, $loan, dueIn($loan, 1))->assertSessionHasErrors(['renew' => __('loans.error.renew_request_limit', ['max' => 2])]);
    expect(renewState($loan))->toBe($before);
});

test('one step moves the due date by at most 7 days, and always forward', function () {
    [, , $borrower, , $loan] = renewScene();
    $before = renewState($loan);

    renewPost($this, $borrower, $loan, dueIn($loan, 8))->assertSessionHasErrors(['renew' => __('loans.error.renew_step_too_long', ['days' => 7])]);
    renewPost($this, $borrower, $loan, dueIn($loan, 0))->assertSessionHasErrors(['renew' => __('loans.error.renew_not_later')]);
    renewPost($this, $borrower, $loan, dueIn($loan, -1))->assertSessionHasErrors(['renew' => __('loans.error.renew_not_later')]);
    foreach (['', 'tomorrow', '31/12/2026', '2026-13-40'] as $bad) {
        renewPost($this, $borrower, $loan, $bad)->assertSessionHasErrors('new_due_date');
    }
    expect(renewState($loan))->toBe($before);

    renewPost($this, $borrower, $loan, dueIn($loan, 7))->assertSessionHasNoErrors();   // exactly 7 is fine
    expect($loan->fresh()->renew_count)->toBe(1);
});

test('the due date can never go later than 60 days after the hand-out: day 60 is accepted, day 61 refused', function () {
    // More turns than the shipped 3, so that the 60-day ceiling (and not the count) is what stops the borrower.
    config(['loans.max_renewals' => 10]);
    [, , $borrower, $approver, $loan] = renewScene(30);            // due on day 30
    $handedOut = $loan->handed_out_at->copy()->startOfDay();
    foreach ([7, 7, 7, 7] as $days) {                              // day 37, 44, 51, 58
        renewBy($loan, $borrower, $approver, $days);
    }
    expect($loan->fresh()->due_date->toDateString())->toBe($handedOut->copy()->addDays(58)->toDateString());
    $before = renewState($loan);
    $cap = $handedOut->copy()->addDays(60);

    renewPost($this, $borrower, $loan, $handedOut->copy()->addDays(61)->format('Y-m-d'))
        ->assertSessionHasErrors(['renew' => __('loans.error.renew_total_too_long', ['date' => $cap->format('d/m/Y')])]);
    expect(renewState($loan))->toBe($before);
    expect(app(LoanRenewalService::class)->latestPossibleDue($loan->fresh())?->toDateString())->toBe($cap->toDateString());

    renewPost($this, $borrower, $loan, $cap->format('Y-m-d'))->assertSessionHasNoErrors();
    renewDecidePost($this, $approver, $loan, 5)->assertSessionHasNoErrors();
    expect($loan->fresh()->due_date->toDateString())->toBe($cap->toDateString());

    // At the ceiling nothing more is possible.
    expect(app(LoanRenewalService::class)->latestPossibleDue($loan->fresh()))->toBeNull();
    renewPost($this, $borrower, $loan, $cap->copy()->addDay()->format('Y-m-d'))->assertSessionHasErrors('renew');
});

test('an asset with its own shorter period lowers the ceiling to twice that period', function () {
    [$lab, $asset] = loanBranch();
    DB::table('loan_assets')->where('id', $asset->id)->update(['max_loan_days' => 5]);
    $borrower = staffUser(['lab_id' => $lab->id]);
    $approver = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($borrower, $asset->fresh(), 1, null, loanDates(5));   // due on day 5, ceiling = day 10
    $cap = $loan->handed_out_at->copy()->startOfDay()->addDays(10);

    renewPost($this, $borrower, $loan, $cap->copy()->addDay()->format('Y-m-d'))
        ->assertSessionHasErrors(['renew' => __('loans.error.renew_total_too_long', ['date' => $cap->format('d/m/Y')])]);
    expect($loan->fresh()->renew_count)->toBe(0);

    renewPost($this, $borrower, $loan, $cap->format('Y-m-d'))->assertSessionHasNoErrors();
    expect($loan->fresh()->due_date->toDateString())->toBe($cap->toDateString());
    expect($approver->id)->not->toBeNull();
});

test('the limits are checked again when the request is decided', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 7);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 7), null);
    $before = renewState($loan);

    // The ceiling was lowered while the request waited.
    config(['loans.max_total_days' => 10]);
    renewDecidePost($this, $approver, $loan, 2)->assertSessionHasErrors('renew');
    expect(renewState($loan))->toBe($before);

    // So was the number of turns.
    config(['loans.max_total_days' => 60, 'loans.max_renewals' => 1]);
    renewDecidePost($this, $approver, $loan, 2)->assertSessionHasErrors(['renew' => __('loans.error.renew_limit', ['max' => 1])]);
    expect(renewState($loan))->toBe($before);

    // A rejection is always possible.
    renewDecidePost($this, $approver, $loan, 2, 'REJECT', 'เกินเพดานที่กำหนดใหม่')->assertSessionHasNoErrors();
    expect(LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail()->status)->toBe('REJECTED');
});

// --- when ---------------------------------------------------------------------------------------------------------------------

test('on the due date itself a renewal can still be asked for; one day later the loan is overdue and it cannot', function () {
    [, , $borrower, , $loan] = renewScene();
    $second = handedOutLoan($borrower, loanExtraAsset(Lab::findOrFail($loan->lab_id)), 1);

    $this->travelTo($loan->due_date->copy()->setTime(16, 0));
    renewPost($this, $borrower, $loan, dueIn($loan, 3))->assertSessionHasNoErrors();
    expect($loan->fresh()->renew_count)->toBe(1);

    $this->travelTo($second->due_date->copy()->addDay()->setTime(8, 0));
    $before = renewState($second);
    renewPost($this, $borrower, $second, dueIn($second, 3))->assertForbidden();
    expect(fn () => app(LoanRenewalService::class)->request($borrower->fresh(), $second->fresh(), dueIn($second, 3), null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_overdue'));
    expect(renewState($second))->toBe($before);
    expect(app(LoanRenewalService::class)->latestPossibleDue($second->fresh()))->toBeNull();
    $this->actingAs($borrower)->get(route('loans.show', $second))->assertOk()->assertDontSee(route('loans.renewals.store', $second), false);
});

test('a renewal is only for a loan whose things are out: not before hand-out, not after it is closed; a partly returned loan still can', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $handler = scientistUser(['lab_id' => $lab->id]);
    $service = app(LoanRenewalService::class);

    foreach (['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'] as $status) {
        $loan = forceLoanStatus(approvedLoan($borrower, $asset, 1), $status);
        renewPost($this, $borrower, $loan, dueIn($loan, 3))->assertForbidden();
        expect(fn () => $service->request($borrower->fresh(), $loan, dueIn($loan, 3), null))
            ->toThrow(LoanDomainException::class, __('loans.error.renew_not_out'));
        expect(LoanDueChange::where('loan_id', $loan->id)->count())->toBe(0);
    }

    $loan = handedOutLoan($borrower, $asset, 2);
    app(LoanCirculationService::class)->receiveReturn($handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
    renewPost($this, $borrower, $loan, dueIn($loan, 3))->assertSessionHasNoErrors();
    expect($loan->fresh()->renew_count)->toBe(1);

    app(LoanCirculationService::class)->receiveReturn($handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    expect($loan->fresh()->status)->toBe('CLOSED');
    renewPost($this, $borrower, $loan, dueIn($loan, 3))->assertForbidden();
    expect(fn () => $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 3), null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_not_out'));
    expect($loan->fresh()->renew_count)->toBe(1);
});

test('the date asked for has itself gone by when the approver looks: approving is refused, rejecting works', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    $pending = LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail();

    $this->travelTo($pending->new_due_date->copy()->addDay()->setTime(9, 0));
    $before = renewState($loan);

    renewDecidePost($this, $approver, $loan, 2)->assertSessionHasErrors(['renew' => __('loans.error.renew_new_due_passed')]);
    expect(fn () => app(LoanRenewalService::class)->decide($approver->fresh(), $loan->fresh(), 2, 'APPROVE', null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_new_due_passed'));
    expect(renewState($loan))->toBe($before);

    renewDecidePost($this, $approver, $loan, 2, 'REJECT', 'วันที่ขอเลื่อนผ่านไปแล้ว')->assertSessionHasNoErrors();
    expect($pending->fresh()->status)->toBe('REJECTED');
    expect($loan->fresh()->due_date->toDateString())->toBe($before['due_date']);
});

test('CURRENT BEHAVIOUR (to confirm): a request made in time may still be approved after the old due date passed, as long as the new date is not in the past', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 5), null);
    $pending = LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail();

    // Two days after the (old) due date: the loan is overdue, the date asked for is still ahead.
    $this->travelTo($pending->old_due_date->copy()->addDays(2)->setTime(9, 0));
    expect($loan->fresh()->isOverdue())->toBeTrue();

    renewDecidePost($this, $approver, $loan, 2)->assertSessionHasNoErrors();

    expect($loan->fresh()->due_date->toDateString())->toBe($pending->new_due_date->toDateString());
    expect($loan->fresh()->isOverdue())->toBeFalse();
    // On the last day asked for it is still accepted.
    expect($pending->fresh()->status)->toBe('APPROVED');
});

test('a request that still waits when the loan is closed is moot: nothing is offered for it and it cannot be approved', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    app(LoanCirculationService::class)->receiveReturn($approver, $loan, [1 => ['qty' => '2', 'condition' => 'GOOD']]);
    expect($loan->fresh()->status)->toBe('CLOSED');
    $before = renewState($loan);

    renewDecidePost($this, $approver, $loan, 2)->assertForbidden();
    expect(fn () => app(LoanRenewalService::class)->decide($approver->fresh(), $loan->fresh(), 2, 'APPROVE', null))
        ->toThrow(LoanDomainException::class, __('loans.error.renew_not_out'));
    expect(renewState($loan))->toBe($before);

    $this->actingAs($approver)->get(route('loans.show', $loan))->assertOk()
        ->assertDontSee(route('loans.renewals.decide', ['loan' => $loan, 'seq' => 2]), false)
        ->assertSee(__('loans.due_status_pending'));   // it stays in the history
    $this->actingAs($approver)->get(route('loans.queue'))->assertOk()->assertViewHas('renewalQueue', fn ($rows) => $rows->isEmpty());
});

// --- who may ask ------------------------------------------------------------------------------------------------------------------

test('the borrower asks; so does the person who typed the loan in; nobody else of the branch, and not an approver who is neither', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    $approver = auditorUser(['lab_id' => $lab->id]);
    $colleague = staffUser(['lab_id' => $lab->id]);

    $onBehalf = submittedLoan($borrower, $asset, 1, $clerk);
    app(\App\Domain\Loan\Services\LoanApprovalService::class)->managerDecide($onBehalf, $approver, 'APPROVE');
    app(LoanCirculationService::class)->handOut($approver, $onBehalf, loanConditions($onBehalf));

    foreach ([$colleague, $approver, adminUser(['lab_id' => null]), scientistUser(['lab_id' => makeLab()->id])] as $stranger) {
        renewPost($this, $stranger, $onBehalf, dueIn($onBehalf, 2))->assertForbidden();
        expect(fn () => app(LoanRenewalService::class)->request($stranger->fresh(), $onBehalf->fresh(), dueIn($onBehalf, 2), null))
            ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));
    }
    expect(LoanDueChange::where('loan_id', $onBehalf->id)->count())->toBe(0);

    renewPost($this, $clerk, $onBehalf, dueIn($onBehalf, 2))->assertSessionHasNoErrors();
    expect(LoanDueChange::where('loan_id', $onBehalf->id)->firstOrFail()->requested_by)->toBe($clerk->id);

    renewPost($this, $borrower, $onBehalf, dueIn($onBehalf, 2))->assertSessionHasNoErrors();
    expect(LoanDueChange::where('loan_id', $onBehalf->id)->where('seq', 2)->firstOrFail()->requested_by)->toBe($borrower->id);
});

test('a borrower whose account was deactivated, or who no longer holds loan.renew, cannot ask', function () {
    [, , $borrower, , $loan] = renewScene();
    $service = app(LoanRenewalService::class);

    $borrower->update(['is_active' => false]);
    expect(fn () => $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null))
        ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));

    $borrower->update(['is_active' => true]);
    $borrower->roles()->detach();
    $role = \App\Models\Role::create(['code' => 'TNORENEW', 'name_th' => 'ยืมได้แต่ต่อไม่ได้']);
    $role->permissions()->attach(\App\Models\Permission::whereIn('code', ['loan.create', 'loan.view_own'])->pluck('id'));
    $borrower->roles()->attach($role);

    renewPost($this, $borrower->fresh(), $loan, dueIn($loan, 2))->assertForbidden();
    expect(fn () => $service->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null))
        ->toThrow(LoanDomainException::class, __('loans.error.not_allowed'));
    expect(LoanDueChange::where('loan_id', $loan->id)->count())->toBe(0);
});

test('the renewal form accepts a date and a reason only: status, sequence, approver and loan fields sent along are ignored', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 2);
    $due = $loan->fresh()->due_date->toDateString();

    renewPost($this, $borrower, $loan, dueIn($loan, 2), null, [
        'status' => 'APPROVED', 'seq' => 9, 'approved_by' => $approver->id, 'requested_by' => $approver->id,
        'old_due_date' => '2020-01-01', 'renew_count' => 0, 'due_date' => dueIn($loan, 6), 'loan_id' => 1,
    ])->assertSessionHasNoErrors();

    $row = LoanDueChange::where('loan_id', $loan->id)->orderByDesc('seq')->firstOrFail();
    expect($row->seq)->toBe(2)->and($row->status)->toBe('PENDING')->and($row->approved_by)->toBeNull()->and($row->requested_by)->toBe($borrower->id);
    expect($row->old_due_date->toDateString())->toBe($due);
    expect($loan->fresh()->due_date->toDateString())->toBe($due)->and($loan->fresh()->renew_count)->toBe(1);
});

// --- who may decide -----------------------------------------------------------------------------------------------------------------

test('an approver of the loan\'s own branch decides a waiting renewal', function (string $kind) {
    [$lab, , $borrower, $first, $loan] = renewScene();
    renewBy($loan, $borrower, $first, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    $approver = navUser($kind, ['lab_id' => $lab->id]);

    renewDecidePost($this, $approver, $loan, 2)->assertRedirect(route('loans.show', $loan))->assertSessionHasNoErrors();

    expect(LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail()->approved_by)->toBe($approver->id);
    expect($loan->fresh()->renew_count)->toBe(2);
})->with(['scientist', 'auditor', 'lab_manager', 'admin+scientist']);

test('nobody else decides it — 403 on the page, an exception in the service — and nothing changes', function (string $kind) {
    [$lab, , $borrower, $first, $loan] = renewScene();
    $other = makeLab();
    renewBy($loan, $borrower, $first, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    $user = match ($kind) {
        'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'scientist with no branch' => scientistUser(['lab_id' => null]),
        'manager of another branch' => auditorUser(['lab_id' => $other->id]),
        'ADMIN + SCIENTIST of another branch' => navUser('admin+scientist', ['lab_id' => $other->id]),
        'ADMIN alone, same branch' => adminUser(['lab_id' => $lab->id]),
        'ADMIN alone, no branch' => adminUser(['lab_id' => null]),
        'STAFF of the same branch' => staffUser(['lab_id' => $lab->id]),
        'the borrower (a STAFF, not an approver)' => $borrower,
        'ADVISOR of the same branch' => navUser('advisor', ['lab_id' => $lab->id]),
    };
    $before = renewState($loan);

    renewDecidePost($this, $user, $loan, 2)->assertForbidden();
    renewDecidePost($this, $user, $loan, 2, 'REJECT', 'ทดสอบสิทธิ์')->assertForbidden();
    foreach (['APPROVE', 'REJECT'] as $decision) {
        expect(fn () => app(LoanRenewalService::class)->decide($user->fresh(), $loan->fresh(), 2, $decision, 'ทดสอบสิทธิ์'))
            ->toThrow(LoanDomainException::class, __('loans.error.approver_not_eligible'));
    }

    expect(renewState($loan))->toBe($before);
})->with([
    'scientist of another branch', 'scientist with no branch', 'manager of another branch',
    'ADMIN + SCIENTIST of another branch', 'ADMIN alone, same branch', 'ADMIN alone, no branch',
    'STAFF of the same branch', 'the borrower (a STAFF, not an approver)', 'ADVISOR of the same branch',
]);

test('a request is decided once: a second decision, an unknown sequence number and a non-numeric one are refused', function () {
    [$lab, $asset, $borrower, $approver, $loan] = renewScene();
    $second = auditorUser(['lab_id' => $lab->id]);
    renewBy($loan, $borrower, $approver, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    renewDecidePost($this, $approver, $loan, 2)->assertSessionHasNoErrors();
    $before = renewState($loan);

    // Already decided (also: the automatic first one).
    foreach ([1, 2] as $seq) {
        renewDecidePost($this, $second, $loan, $seq, 'REJECT', 'ขอเปลี่ยนคำตัดสิน')->assertSessionHasErrors(['renew' => __('loans.error.invalid_transition')]);
        renewDecidePost($this, $second, $loan, $seq)->assertSessionHasErrors(['renew' => __('loans.error.invalid_transition')]);
    }
    // Never existed.
    renewDecidePost($this, $second, $loan, 7)->assertSessionHasErrors(['renew' => __('loans.error.invalid_transition')]);
    // Not a number: no such POST route (404, or 405 when the router finds the path under another verb).
    foreach (['abc', '2-1', '2.0'] as $notANumber) {
        expect(renewDecidePost($this, $second, $loan, $notANumber)->getStatusCode())->toBeIn([404, 405]);
    }

    expect(renewState($loan))->toBe($before);

    // The sequence number belongs to ITS loan: another loan's URL with the same number reaches nothing.
    $otherLoan = handedOutLoan($borrower, $asset, 1);
    renewDecidePost($this, $second, $otherLoan, 2)->assertSessionHasErrors(['renew' => __('loans.error.invalid_transition')]);
    expect(LoanDueChange::where('loan_id', $otherLoan->id)->count())->toBe(0);
});

test('the database itself refuses a due-date change that does not move forward, and a duplicate sequence number', function () {
    [, , $borrower, , $loan] = renewScene();
    $row = fn (array $over) => array_merge([
        'loan_id' => $loan->id, 'seq' => 1, 'old_due_date' => '2026-11-20', 'new_due_date' => '2026-11-25',
        'requested_by' => $borrower->id, 'status' => 'PENDING', 'created_at' => now(),
    ], $over);

    expect(fn () => DB::table('loan_due_changes')->insert($row(['new_due_date' => '2026-11-20'])))->toThrow(QueryException::class);
    expect(fn () => DB::table('loan_due_changes')->insert($row(['new_due_date' => '2026-11-19'])))->toThrow(QueryException::class);
    DB::table('loan_due_changes')->insert($row([]));
    expect(fn () => DB::table('loan_due_changes')->insert($row(['new_due_date' => '2026-11-26'])))->toThrow(QueryException::class);
    expect(fn () => DB::table('loan_due_changes')->insert($row(['seq' => 2, 'status' => 'MAYBE'])))->toThrow(QueryException::class);
});

// --- the approver's queue and the notices ---------------------------------------------------------------------------------------------

test('the approval queue lists the waiting renewals of the user\'s own branch only, and an advisor does not get that list', function () {
    [$labA, , $borrowerA, $approverA, $loanA] = renewScene();
    [$labB, , $borrowerB, $approverB, $loanB] = renewScene();
    foreach ([[$loanA, $borrowerA, $approverA], [$loanB, $borrowerB, $approverB]] as [$loan, $borrower, $approver]) {
        renewBy($loan, $borrower, $approver, 2);
        app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    }

    $this->actingAs($approverA)->get(route('loans.queue'))->assertOk()
        ->assertSee(__('loans.queue_renew_section'))->assertSee($loanA->doc_no)->assertDontSee($loanB->doc_no)
        ->assertViewHas('renewalQueue', fn ($rows) => $rows->pluck('loan_id')->all() === [$loanA->id]);
    $this->actingAs($approverB)->get(route('loans.queue'))->assertOk()->assertSee($loanB->doc_no)->assertDontSee($loanA->doc_no);

    $advisor = navUser('advisor', ['lab_id' => $labA->id]);
    $this->actingAs($advisor)->get(route('loans.queue'))->assertOk()
        ->assertDontSee(__('loans.queue_renew_section'))->assertDontSee($loanA->doc_no);
    expect($labB->id)->not->toBe($labA->id);
});

test('a waiting renewal is announced in the system to every approver of the branch, by email to the warehouse managers only, without any personal detail', function () {
    [$lab, $asset] = loanBranch();
    $other = makeLab();
    $borrower = staffUser(['lab_id' => $lab->id, 'full_name' => 'ผู้ยืมที่ไม่ควรอยู่ในข้อความ']);
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $manager = auditorUser(['lab_id' => $lab->id]);
    $silent = [
        'inactive scientist' => scientistUser(['lab_id' => $lab->id, 'is_active' => false]),
        'scientist of another branch' => scientistUser(['lab_id' => $other->id]),
        'manager of another branch' => auditorUser(['lab_id' => $other->id]),
        'scientist with no branch' => scientistUser(['lab_id' => null]),
        'ADMIN of the branch' => adminUser(['lab_id' => $lab->id]),
        'STAFF of the branch' => staffUser(['lab_id' => $lab->id]),
    ];
    $loan = handedOutLoan($borrower, $asset, 1, $scientist);

    renewPost($this, $borrower, $loan, dueIn($loan, 2))->assertSessionHasNoErrors();     // automatic: nobody is told
    expect(Notification::count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    renewPost($this, $borrower, $loan, dueIn($loan, 2), 'เหตุผลของผู้ยืม')->assertSessionHasNoErrors();
    $pending = LoanDueChange::where('loan_id', $loan->id)->where('seq', 2)->firstOrFail();

    $notices = loanNotices(LoanNotifier::TYPE_RENEW_PENDING);
    expect($notices->pluck('user_id')->sort()->values()->all())->toBe(collect([$scientist->id, $manager->id])->sort()->values()->all());
    foreach ($notices as $notice) {
        expect($notice->link_url)->toBe(route('loans.show', $loan));
        expect($notice->body)->toContain($loan->doc_no)->toContain($pending->new_due_date->format('d/m/Y'));
        foreach ([$notice->title, $notice->body] as $text) {
            expect($text)->not->toContain('ผู้ยืมที่ไม่ควรอยู่ในข้อความ')->not->toContain('เหตุผลของผู้ยืม')->not->toContain((string) $borrower->email);
        }
    }
    expect(loanMailCount($manager))->toBe(1)->and(loanMailCount($scientist))->toBe(0)->and(loanMailCount($borrower))->toBe(0);
    foreach ($silent as $who => $user) {
        expect(Notification::where('user_id', $user->id)->count())->toBe(0, "{$who} must not be told");
        expect(loanMailCount($user))->toBe(0, "{$who} must not be emailed");
    }
});

test('the borrower is told the result of a renewal; a refused request and a failed approval tell nobody', function () {
    [, , $borrower, $approver, $loan] = renewScene();
    renewBy($loan, $borrower, $approver, 2);
    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 2), null);

    renewDecidePost($this, $approver, $loan, 9)->assertSessionHasErrors('renew');   // refused: no notice
    expect(loanNotices(LoanNotifier::TYPE_RENEW_DECISION))->toHaveCount(0);

    renewDecidePost($this, $approver, $loan, 2, 'REJECT', 'เหตุผลภายในของผู้อนุมัติ')->assertSessionHasNoErrors();

    $notices = loanNotices(LoanNotifier::TYPE_RENEW_DECISION);
    expect($notices)->toHaveCount(1);
    expect($notices[0]->user_id)->toBe($borrower->id)->and($notices[0]->link_url)->toBe(route('loans.show', $loan));
    expect($notices[0]->title)->toBe(__('loans.notify.renew_rejected_title', ['doc_no' => $loan->doc_no]));
    expect($notices[0]->body)->toContain($loan->fresh()->due_date->format('d/m/Y'))->not->toContain('เหตุผลภายในของผู้อนุมัติ')->not->toContain((string) $approver->full_name);
    expect(loanMailCount($borrower))->toBe(1);

    app(LoanRenewalService::class)->request($borrower->fresh(), $loan->fresh(), dueIn($loan, 3), null);
    renewDecidePost($this, $approver, $loan, 3)->assertSessionHasNoErrors();
    $approved = loanNotices(LoanNotifier::TYPE_RENEW_DECISION)->last();
    expect($approved->title)->toBe(__('loans.notify.renew_approved_title', ['doc_no' => $loan->doc_no]));
    expect($approved->body)->toContain($loan->fresh()->due_date->format('d/m/Y'));
});

// =====================================================================================================================
// THE SELF RULE (waits for the CEO's confirmation).
// Today: an approver of the branch MAY decide the renewal of their own loan; the control is the audit row
// (LOAN_SELF_RENEW_APPROVE / LOAN_SELF_RENEW_REJECT, self_approval = true). The spec's BR-L07 said "not the borrower".
// If the CEO decides to forbid it, the tests of this section are the ones to flip.
// =====================================================================================================================

test('[SELF] an approver who is the borrower decides their own renewal, and it is recorded as SELF, never as an ordinary decision', function (string $decision) {
    [$lab, $asset] = loanBranch();
    $me = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]);
    $colleague = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan($me, $asset, 1, $colleague);
    renewBy($loan, $me, $colleague, 2);
    app(LoanRenewalService::class)->request($me->fresh(), $loan->fresh(), dueIn($loan, 2), null);
    $dueBefore = $loan->fresh()->due_date->toDateString();
    $wanted = dueIn($loan, 2);

    $this->actingAs($me)->get(route('loans.show', $loan))->assertOk()->assertSee(__('loans.self_renew_note'));
    renewDecidePost($this, $me, $loan, 2, $decision, 'ตัดสินคำขอของตนเอง')->assertRedirect(route('loans.show', $loan))->assertSessionHasNoErrors();

    $audit = loanRenewAudit($loan)->last();
    expect($audit->action)->toBe('LOAN_SELF_RENEW_'.$decision)->and($audit->user_id)->toBe($me->id)->and($audit->message)->toBe('borrower = approver');
    expect($audit->new_value['self_approval'])->toBeTrue()->and($audit->new_value['approver_is_requester'])->toBeTrue()->and($audit->new_value['approver_id'])->toBe($me->id);
    expect(loanAudit('LOAN_RENEW_'.$decision, $loan))->toHaveCount(0);

    expect($loan->fresh()->due_date->toDateString())->toBe($decision === 'APPROVE' ? $wanted : $dueBefore);
    expect($loan->fresh()->renew_count)->toBe($decision === 'APPROVE' ? 2 : 1);
    // They know the result: no notice to themselves.
    expect(loanNotices(LoanNotifier::TYPE_RENEW_DECISION))->toHaveCount(0);
})->with(['APPROVE', 'REJECT']);

test('[SELF] the clerk who typed the loan in and asked for the renewal may approve it too: not SELF (they are not the borrower), but the audit row says requester = approver', function () {
    [$lab, $asset] = loanBranch();
    $borrower = staffUser(['lab_id' => $lab->id]);
    $clerk = scientistUser(['lab_id' => $lab->id]);
    $other = auditorUser(['lab_id' => $lab->id]);
    $loan = submittedLoan($borrower, $asset, 1, $clerk);
    app(\App\Domain\Loan\Services\LoanApprovalService::class)->managerDecide($loan, $other, 'APPROVE');
    app(LoanCirculationService::class)->handOut($other, $loan, loanConditions($loan));

    renewPost($this, $clerk, $loan, dueIn($loan, 2))->assertSessionHasNoErrors();
    renewPost($this, $clerk, $loan, dueIn($loan, 2))->assertSessionHasNoErrors();
    renewDecidePost($this, $clerk, $loan, 2)->assertSessionHasNoErrors();

    $audit = loanRenewAudit($loan)->last();
    expect($audit->action)->toBe('LOAN_RENEW_APPROVE');
    expect($audit->new_value['self_approval'])->toBeFalse()->and($audit->new_value['approver_is_requester'])->toBeTrue();
    expect($loan->fresh()->renew_count)->toBe(2);
});
