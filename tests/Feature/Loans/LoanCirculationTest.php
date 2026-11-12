<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanApprovalService;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanService;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanLine;
use App\Models\LoanMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 3 (WP-3.6): the counter — hand-out (APPROVED -> ON_LOAN), returns (-> PARTIALLY_RETURNED ->
 * CLOSED) — through the REAL routes and LoanCirculationService / LoanLedgerService.
 *
 * What is pinned here: the quantities (`loan_assets.qty_on_loan`, the lines), one `loan_movements` row per line
 * (loan_id / loan_line_id / condition), an unbroken hash chain, `loans:verify` (a)(b)(c), and that every refusal
 * writes NOTHING (compared before / after with loanCounterState()).
 *
 * Who may stand at the counter, the SELF rule, the pages and the menu are in LoanCounterAccessTest.
 */

beforeEach(function () {
    Mail::fake();
});

const WEAR_REASON = 'สึกหรอจากการใช้งานปกติ';   // >= 10 characters

/** @return array{\App\Models\Lab, \App\Models\LoanAsset, \App\Models\User, \App\Models\User} lab, POOL asset (10), borrower (STAFF), handler (SCIENTIST) */
function counterScene(int $qty = 10): array
{
    [$lab, $asset] = loanBranch($qty);

    return [$lab, $asset, staffUser(['lab_id' => $lab->id]), scientistUser(['lab_id' => $lab->id])];
}

// --- the whole cycle ---------------------------------------------------------------------------------------------

test('the whole cycle through the pages: hand out -> return part -> return the rest closes the loan; quantities, movements, chain and loans:verify all agree', function () {
    [$lab, $pool, $borrower, $handler] = counterScene(10);
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    // Two documents: `$loan` has two lines (1 piece of the pool + the unit piece), `$loan3` takes 3 pieces of the pool.
    $loan = approvedLoan($borrower, [$pool, $unit], 1);
    $loan3 = approvedLoan($borrower, $pool, 3);

    // ---- hand-out ----
    $poolLine = $loan3->lines()->firstOrFail();
    loanHandoutPost($this, $handler, $loan3, [$poolLine->line_no => ['condition' => 'FAIR', 'note' => 'มีรอยขีดข่วนเล็กน้อย']])
        ->assertRedirect(route('loans.show', $loan3))
        ->assertSessionHas('status', __('loans.handed_out'));

    $out = $loan3->fresh();
    expect($out->status)->toBe('ON_LOAN')->and($out->handed_out_at)->not->toBeNull()->and($out->closed_at)->toBeNull();
    expect($pool->fresh()->qty_on_loan)->toBe('3.000000')->and($pool->fresh()->qty_total)->toBe('10.000000')->and($pool->fresh()->available())->toBe('7.000000');
    $poolLine = $poolLine->fresh();
    expect($poolLine->condition_out)->toBe('FAIR')->and($poolLine->condition_out_note)->toBe('มีรอยขีดข่วนเล็กน้อย');
    expect($poolLine->qty_returned_ok)->toBe('0.000000')->and($poolLine->outstanding())->toBe('3.000000');

    $handout = LoanMovement::where('loan_id', $loan3->id)->get();
    expect($handout)->toHaveCount(1);
    expect($handout[0]->movement_type)->toBe('HANDOUT')->and($handout[0]->qty)->toBe('3.000000');
    expect($handout[0]->loan_asset_id)->toBe($pool->id)->and($handout[0]->lab_id)->toBe($lab->id);
    expect($handout[0]->loan_line_id)->toBe($poolLine->id)->and($handout[0]->condition_recorded)->toBe('FAIR');
    expect($handout[0]->qty_on_loan_after)->toBe('3.000000')->and($handout[0]->qty_total_after)->toBe('10.000000');
    expect($handout[0]->created_by)->toBe($handler->id)->and($handout[0]->approved_by)->toBeNull();
    expect($handout[0]->note)->toBe('มีรอยขีดข่วนเล็กน้อย');

    $audit = loanAudit('LOAN_HANDOUT', $loan3);
    expect($audit)->toHaveCount(1);
    expect($audit[0]->user_id)->toBe($handler->id);
    expect($audit[0]->new_value['handler_is_borrower'])->toBeFalse()->and($audit[0]->new_value['status'])->toBe('ON_LOAN');
    expect($audit[0]->new_value['borrower_id'])->toBe($borrower->id)->and($audit[0]->new_value['lab_id'])->toBe($lab->id);
    expect(loanAudit('LOAN_SELF_HANDOUT', $loan3))->toHaveCount(0);

    // The two-line loan goes out too: one movement per line, in one action.
    loanHandoutPost($this, $handler, $loan)->assertRedirect(route('loans.show', $loan));
    expect(LoanMovement::where('loan_id', $loan->id)->where('movement_type', 'HANDOUT')->count())->toBe(2);
    expect($pool->fresh()->qty_on_loan)->toBe('4.000000')->and($unit->fresh()->qty_on_loan)->toBe('1.000000');
    expect($unit->fresh()->available())->toBe('0.000000');

    $this->artisan('loans:verify')->assertExitCode(0);

    // ---- a partial return: 2 of the 3 ----
    loanReturnPost($this, $handler, $loan3, [$poolLine->line_no => ['qty' => '2', 'condition' => 'FAIR']])
        ->assertRedirect(route('loans.show', $loan3))
        ->assertSessionHas('status', __('loans.returned_partial'));

    $part = $loan3->fresh();
    expect($part->status)->toBe('PARTIALLY_RETURNED')->and($part->closed_at)->toBeNull();
    expect($poolLine->fresh()->qty_returned_ok)->toBe('2.000000')->and($poolLine->fresh()->outstanding())->toBe('1.000000');
    expect($pool->fresh()->qty_on_loan)->toBe('2.000000');

    $back = LoanMovement::where('loan_id', $loan3->id)->where('movement_type', 'RETURN_OK')->get();
    expect($back)->toHaveCount(1);
    expect($back[0]->qty)->toBe('2.000000')->and($back[0]->loan_line_id)->toBe($poolLine->id)->and($back[0]->condition_recorded)->toBe('FAIR');
    expect($back[0]->qty_on_loan_after)->toBe('2.000000')->and($back[0]->created_by)->toBe($handler->id);

    $returns = loanAudit('LOAN_RETURN', $loan3);
    expect($returns)->toHaveCount(1);
    expect($returns[0]->new_value['closed'])->toBeFalse()->and($returns[0]->new_value['status'])->toBe('PARTIALLY_RETURNED');
    expect($returns[0]->new_value['returned'][0]['line_no'])->toBe($poolLine->line_no);

    $this->artisan('loans:verify')->assertExitCode(0);

    // ---- the rest: the loan closes ----
    loanReturnPost($this, $handler, $loan3, [$poolLine->line_no => ['qty' => '1', 'condition' => 'FAIR']])
        ->assertRedirect(route('loans.show', $loan3))
        ->assertSessionHas('status', __('loans.returned_closed'));

    $closed = $loan3->fresh();
    expect($closed->status)->toBe('CLOSED')->and($closed->closed_at)->not->toBeNull();
    expect($poolLine->fresh()->outstanding())->toBe('0.000000');
    expect($pool->fresh()->qty_on_loan)->toBe('1.000000');   // the two-line loan still has one piece out
    expect(loanAudit('LOAN_RETURN', $loan3))->toHaveCount(2);
    expect(loanAudit('LOAN_RETURN', $loan3)[1]->new_value['closed'])->toBeTrue();

    // The two-line loan: one line back = PARTIALLY_RETURNED, the other = CLOSED.
    [$lineA, $lineB] = $loan->lines()->get()->all();
    loanReturnPost($this, $handler, $loan, [$lineA->line_no => ['qty' => '1', 'condition' => 'GOOD'], $lineB->line_no => ['qty' => '', 'condition' => 'GOOD']])
        ->assertRedirect(route('loans.show', $loan));
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
    loanReturnPost($this, $handler, $loan, [$lineB->line_no => ['qty' => '1', 'condition' => 'GOOD']])->assertRedirect(route('loans.show', $loan));
    expect($loan->fresh()->status)->toBe('CLOSED');

    expect($pool->fresh()->qty_on_loan)->toBe('0.000000')->and($unit->fresh()->qty_on_loan)->toBe('0.000000');
    expect($pool->fresh()->available())->toBe('10.000000')->and($unit->fresh()->available())->toBe('1.000000');

    // REGISTER + 2 HANDOUT + 3 RETURN_OK on the pool; REGISTER + HANDOUT + RETURN_OK on the unit.
    expect(LoanMovement::where('loan_asset_id', $pool->id)->pluck('movement_type')->all())
        ->toBe(['REGISTER', 'HANDOUT', 'HANDOUT', 'RETURN_OK', 'RETURN_OK', 'RETURN_OK']);
    expect(LoanMovement::where('loan_asset_id', $unit->id)->pluck('movement_type')->all())->toBe(['REGISTER', 'HANDOUT', 'RETURN_OK']);
    assertLoanChainIntact($pool);
    assertLoanChainIntact($unit);

    $this->artisan('loans:verify')->expectsOutputToContain(__('loans.verify_cmd.ok', ['count' => 2]))->assertExitCode(0);
});

test('a STUDENT\'s loan approved by the advisor can be handed out at once (no branch step, CEO 2026-11-05)', function () {
    [$lab, $asset, , $handler] = counterScene();
    $student = studentUser(['lab_id' => $lab->id]);
    $loan = approvedLoan($student, $asset, 2);
    expect($loan->status)->toBe('APPROVED')->and($loan->approved_by)->toBeNull()->and($loan->advisor_signed_at)->not->toBeNull();

    loanHandoutPost($this, $handler, $loan)->assertRedirect(route('loans.show', $loan));

    expect($loan->fresh()->status)->toBe('ON_LOAN')->and($asset->fresh()->qty_on_loan)->toBe('2.000000');
});

test('what goes out is what was APPROVED, not what was asked for', function () {
    [$lab, $asset, $borrower, $handler] = counterScene();
    $loan = submittedLoan($borrower, $asset, 5);
    $line = $loan->lines()->firstOrFail();
    app(LoanApprovalService::class)->managerDecide($loan, scientistUser(['lab_id' => $lab->id]), 'APPROVE', 'ให้ยืมได้สามชิ้นเท่านั้น', null, [$line->line_no => '3']);

    // A quantity smuggled into the hand-out form is ignored: the form takes conditions only.
    $this->actingAs($handler)->post(route('loans.handout', $loan), ['lines' => [$line->line_no => ['condition' => 'GOOD', 'qty' => '5']]])
        ->assertRedirect(route('loans.show', $loan));

    expect($asset->fresh()->qty_on_loan)->toBe('3.000000');
    expect(LoanMovement::where('loan_id', $loan->id)->firstOrFail()->qty)->toBe('3.000000');
    expect($line->fresh()->outstanding())->toBe('3.000000');
});

test('handing out on the due date itself is allowed; the day after it is refused and nothing is written', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $onTime = approvedLoan($borrower, $asset, 1);
    $late = approvedLoan($borrower, $asset, 1);

    $this->travelTo($onTime->due_date->copy()->setTime(9, 0));
    loanHandoutPost($this, $handler, $onTime)->assertRedirect(route('loans.show', $onTime));
    expect($onTime->fresh()->status)->toBe('ON_LOAN');

    $this->travelTo($late->due_date->copy()->addDay()->setTime(9, 0));
    $before = loanCounterState($late);
    loanHandoutPost($this, $handler, $late)->assertSessionHasErrors(['handout' => __('loans.error.handout_due_passed')]);
    expect(fn () => app(LoanCirculationService::class)->handOut($handler, $late, loanConditions($late)))
        ->toThrow(LoanDomainException::class, __('loans.error.handout_due_passed'));
    expect(loanCounterState($late))->toBe($before);
    expect($late->fresh()->status)->toBe('APPROVED');
});

// --- the condition written down (BR-L09) ---------------------------------------------------------------------------

test('hand-out needs a condition for EVERY line, and a note when a piece goes out DAMAGED', function () {
    [$lab, $asset, $borrower, $handler] = counterScene();
    $second = loanExtraAsset($lab, 5);
    $loan = approvedLoan($borrower, [$asset, $second], 1);
    [$lineA, $lineB] = $loan->lines()->get()->all();
    $service = app(LoanCirculationService::class);
    $before = loanCounterState($loan);

    // One line left out.
    expect(fn () => $service->handOut($handler, $loan, [$lineA->line_no => ['condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.condition_required'));
    loanHandoutPost($this, $handler, $loan, [$lineA->line_no => ['condition' => 'GOOD']])
        ->assertSessionHasErrors(['handout' => __('loans.error.condition_required')]);
    // A word that is not a condition.
    expect(fn () => $service->handOut($handler, $loan, [$lineA->line_no => ['condition' => 'PERFECT'], $lineB->line_no => ['condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.condition_required'));
    loanHandoutPost($this, $handler, $loan, [$lineA->line_no => ['condition' => 'PERFECT'], $lineB->line_no => ['condition' => 'GOOD']])
        ->assertSessionHasErrors("lines.{$lineA->line_no}.condition");
    // No lines at all.
    $this->actingAs($handler)->post(route('loans.handout', $loan), [])->assertSessionHasErrors('lines');
    // DAMAGED without a note (blank counts as none).
    expect(fn () => $service->handOut($handler, $loan, [$lineA->line_no => ['condition' => 'DAMAGED', 'note' => '   '], $lineB->line_no => ['condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.condition_note_required'));

    expect(loanCounterState($loan))->toBe($before);

    $service->handOut($handler, $loan, [$lineA->line_no => ['condition' => 'DAMAGED', 'note' => 'ฝาครอบแตก'], $lineB->line_no => ['condition' => 'GOOD']]);
    expect($lineA->fresh()->condition_out)->toBe('DAMAGED')->and($lineA->fresh()->condition_out_note)->toBe('ฝาครอบแตก');
    expect($lineB->fresh()->condition_out)->toBe('GOOD')->and($lineB->fresh()->condition_out_note)->toBeNull();
});

test('a piece that comes back WORSE than it went out needs a wear reason of at least 10 characters; the same or better needs none', function (string $out, string $in, bool $needsReason) {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = approvedLoan($borrower, $asset, 2);
    $line = $loan->lines()->firstOrFail();
    app(LoanCirculationService::class)->handOut($handler, $loan, [$line->line_no => ['condition' => $out, 'note' => $out === 'DAMAGED' ? 'ชำรุดอยู่ก่อนแล้ว' : null]]);
    $before = loanCounterState($loan);

    if ($needsReason) {
        foreach ([null, '', '123456789'] as $short) {   // 9 characters is still too short
            loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => $in, 'note' => $short]])
                ->assertSessionHasErrors(['return' => __('loans.error.wear_reason_required')]);
        }
        expect(loanCounterState($loan))->toBe($before);

        loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => $in, 'note' => '1234567890']])
            ->assertSessionHasNoErrors();
    } else {
        loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => $in]])->assertSessionHasNoErrors();
    }

    $movement = LoanMovement::where('loan_id', $loan->id)->where('movement_type', 'RETURN_OK')->firstOrFail();
    expect($movement->condition_recorded)->toBe($in)->and($movement->note)->toBe($needsReason ? '1234567890' : null);
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED');
})->with([
    'GOOD -> GOOD' => ['GOOD', 'GOOD', false],
    'GOOD -> FAIR' => ['GOOD', 'FAIR', true],
    'GOOD -> DAMAGED' => ['GOOD', 'DAMAGED', true],
    'FAIR -> FAIR' => ['FAIR', 'FAIR', false],
    'FAIR -> GOOD' => ['FAIR', 'GOOD', false],
    'FAIR -> DAMAGED' => ['FAIR', 'DAMAGED', true],
    'DAMAGED -> DAMAGED' => ['DAMAGED', 'DAMAGED', false],
]);

// --- refusals: nothing is written ----------------------------------------------------------------------------------

test('a second hand-out of the same loan is refused (403 on the page, invalid transition in the service) and nothing is written twice', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = approvedLoan($borrower, $asset, 2);
    loanHandoutPost($this, $handler, $loan)->assertRedirect(route('loans.show', $loan));
    $before = loanCounterState($loan);

    loanHandoutPost($this, $handler, $loan)->assertForbidden();
    $this->actingAs($handler)->get(route('loans.handout.form', $loan))->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->handOut($handler, $loan, loanConditions($loan)))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect(loanCounterState($loan))->toBe($before);
    expect($asset->fresh()->qty_on_loan)->toBe('2.000000');
});

test('a loan that is not APPROVED cannot be handed out, whatever its status', function (string $status) {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = forceLoanStatus(approvedLoan($borrower, $asset, 2), $status);
    $before = loanCounterState($loan);

    loanHandoutPost($this, $handler, $loan)->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->handOut($handler, $loan, loanConditions($loan)))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect(loanCounterState($loan))->toBe($before);
    expect($asset->fresh()->qty_on_loan)->toBe('0.000000');
})->with(['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'REJECTED', 'CANCELLED', 'CLOSED']);

test('a UNIT piece that is already out cannot go out again on another loan (BR-L15)', function () {
    [$lab, , $borrower, $handler] = counterScene();
    $unit = loanExtraAsset($lab, 1, null, 'UNIT');
    $other = staffUser(['lab_id' => $lab->id]);
    // Approval reserves nothing, so both requests for the one piece can be approved.
    $first = approvedLoan($borrower, $unit, 1);
    $second = approvedLoan($other, $unit, 1);

    loanHandoutPost($this, $handler, $first)->assertRedirect(route('loans.show', $first));
    $before = loanCounterState($second);

    loanHandoutPost($this, $handler, $second)
        ->assertSessionHasErrors(['handout' => __('loans.error.handout_not_available', ['item' => (string) $unit->item->name_th])]);
    expect(fn () => app(LoanCirculationService::class)->handOut($handler, $second, loanConditions($second)))->toThrow(LoanDomainException::class);

    expect(loanCounterState($second))->toBe($before);
    expect($second->fresh()->status)->toBe('APPROVED');
    expect($unit->fresh()->qty_on_loan)->toBe('1.000000');
    expect(LoanMovement::where('loan_asset_id', $unit->id)->where('movement_type', 'HANDOUT')->count())->toBe(1);

    // Once the piece is back, the second loan goes out.
    loanReturnPost($this, $handler, $first, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHasNoErrors();
    loanHandoutPost($this, $handler, $second)->assertRedirect(route('loans.show', $second));
    expect($unit->fresh()->qty_on_loan)->toBe('1.000000')->and($second->fresh()->status)->toBe('ON_LOAN');
});

test('hand-out is all or nothing: when the LAST line has not enough left, the first line is not handed out either', function () {
    [$lab, $first, $borrower, $handler] = counterScene(10);
    $second = loanExtraAsset($lab, 3);   // registered later = higher id = handed out after `$first`
    expect($second->id)->toBeGreaterThan($first->id);

    $loan = approvedLoan($borrower, [$first, $second], 2);
    // Somebody else takes the second asset first (approval reserved nothing).
    handedOutLoan(staffUser(['lab_id' => $lab->id]), $second, 2);
    expect($second->fresh()->available())->toBe('1.000000');
    $before = loanCounterState($loan);

    loanHandoutPost($this, $handler, $loan)
        ->assertSessionHasErrors(['handout' => __('loans.error.handout_not_available', ['item' => (string) $second->item->name_th])]);

    expect(loanCounterState($loan))->toBe($before);
    expect($loan->fresh()->status)->toBe('APPROVED')->and($loan->fresh()->handed_out_at)->toBeNull();
    expect($first->fresh()->qty_on_loan)->toBe('0.000000');
    expect(LoanMovement::where('loan_id', $loan->id)->count())->toBe(0);
    foreach ($loan->lines()->get() as $line) {
        expect($line->condition_out)->toBeNull();
    }
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('an asset retired after the approval cannot be handed out', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 5);
    $loan = approvedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1, $approver);
    loanService()->retire($manager, $asset->fresh(), 'ปลดระวางเพราะเสื่อมสภาพทั้งชุด', $approver->id);
    $before = loanCounterState($loan);

    loanHandoutPost($this, $manager, $loan)->assertSessionHasErrors(['handout' => __('loans.error.asset_not_lendable')]);

    expect(loanCounterState($loan))->toBe($before);
});

test('returning more than is still out is refused and nothing is written, on the first return and after a partial one', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = handedOutLoan($borrower, $asset, 3);
    $line = $loan->lines()->firstOrFail();
    $before = loanCounterState($loan);

    loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '4', 'condition' => 'GOOD']])
        ->assertSessionHasErrors(['return' => __('loans.error.return_too_much')]);
    expect(loanCounterState($loan))->toBe($before);

    loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '2', 'condition' => 'GOOD']])->assertSessionHasNoErrors();
    $afterPartial = loanCounterState($loan);

    // 2 are back, 1 is out: asking for 2 more is too much (the "returned twice" case, one after the other).
    loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '2', 'condition' => 'GOOD']])
        ->assertSessionHasErrors(['return' => __('loans.error.return_too_much')]);
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($handler, $loan, [$line->line_no => ['qty' => '2', 'condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.return_too_much'));

    expect(loanCounterState($loan))->toBe($afterPartial);
    expect($asset->fresh()->qty_on_loan)->toBe('1.000000');
});

test('a return with one good line and one bad line writes neither (one transaction)', function () {
    [$lab, $first, $borrower, $handler] = counterScene();
    $second = loanExtraAsset($lab, 5);
    $loan = handedOutLoan($borrower, [$first, $second], 2);
    [$lineA, $lineB] = $loan->lines()->get()->all();
    $before = loanCounterState($loan);

    loanReturnPost($this, $handler, $loan, [
        $lineA->line_no => ['qty' => '1', 'condition' => 'GOOD'],
        $lineB->line_no => ['qty' => '3', 'condition' => 'GOOD'],   // only 2 are out
    ])->assertSessionHasErrors(['return' => __('loans.error.return_too_much')]);

    expect(loanCounterState($loan))->toBe($before);
    expect($loan->fresh()->status)->toBe('ON_LOAN');
});

test('a return after the loan is CLOSED is refused (403 on the page, invalid transition in the service)', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = handedOutLoan($borrower, $asset, 1);
    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHasNoErrors();
    expect($loan->fresh()->status)->toBe('CLOSED');
    $before = loanCounterState($loan);

    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertForbidden();
    $this->actingAs($handler)->get(route('loans.return.form', $loan))->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect(loanCounterState($loan))->toBe($before);
    expect($asset->fresh()->qty_on_loan)->toBe('0.000000');
});

test('a return on a loan that was never handed out is refused', function (string $status) {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = forceLoanStatus(approvedLoan($borrower, $asset, 1), $status);
    $before = loanCounterState($loan);

    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']])->assertForbidden();
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']]))
        ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));

    expect(loanCounterState($loan))->toBe($before);
})->with(['SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED']);

test('a return that names nothing, a bad quantity, a missing condition or a line of another document is refused', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = handedOutLoan($borrower, $asset, 2);
    $service = app(LoanCirculationService::class);
    $before = loanCounterState($loan);

    // Nothing to take back: blank and zero both mean "not this line".
    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '', 'condition' => 'GOOD']])->assertSessionHasErrors(['return' => __('loans.error.return_nothing')]);
    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '0', 'condition' => 'GOOD']])->assertSessionHasErrors(['return' => __('loans.error.return_nothing')]);
    $this->actingAs($handler)->post(route('loans.return', $loan), [])->assertSessionHasErrors('lines');

    // Not a whole number: stopped by the Form Request, and by the service for a caller that skips it.
    foreach (['1.5', '-1', 'abc', '1e2'] as $bad) {
        loanReturnPost($this, $handler, $loan, [1 => ['qty' => $bad, 'condition' => 'GOOD']])->assertSessionHasErrors('lines.1.qty');
        expect(fn () => $service->receiveReturn($handler, $loan, [1 => ['qty' => $bad, 'condition' => 'GOOD']]))
            ->toThrow(LoanDomainException::class, __('loans.error.qty_whole'));
    }

    // No condition for a line that has a quantity.
    loanReturnPost($this, $handler, $loan, [1 => ['qty' => '1']])->assertSessionHasErrors(['return' => __('loans.error.condition_required')]);

    // A line number this document does not have.
    loanReturnPost($this, $handler, $loan, [9 => ['qty' => '1', 'condition' => 'GOOD']])->assertSessionHasErrors(['return' => __('loans.error.line_mismatch')]);

    expect(loanCounterState($loan))->toBe($before);
});

test('DAMAGED / LOST returns (open since step 4) are never an ordinary return: they become incidents, RETURN_OK is not written for them, and the ledger still refuses the two types by name', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = handedOutLoan($borrower, $asset, 4);
    $line = $loan->lines()->firstOrFail();
    $before = loanCounterState($loan);

    // Refused as before step 4: a word that is no return type, and the two incident types asked of takeBack() directly
    // (they are written by LoanLedgerService::takeBackIncident() only, for an incident row).
    loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'GOOD', 'outcome' => 'STOLEN']])
        ->assertSessionHasErrors("lines.{$line->line_no}.outcome");
    expect(fn () => app(LoanCirculationService::class)->receiveReturn($handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'GOOD', 'outcome' => 'STOLEN']]))
        ->toThrow(LoanDomainException::class, __('loans.error.return_type_invalid'));
    foreach (['RETURN_DAMAGED', 'MARK_LOST'] as $type) {
        expect(fn () => loanService()->takeBack($handler, $asset->fresh(), $loan, $line, $type, '1.000000', 'DAMAGED', WEAR_REASON))
            ->toThrow(LoanDomainException::class, __('loans.error.incident_not_available'));
    }
    foreach (['HANDOUT', 'WRITE_OFF', 'ADJUST_IN', ''] as $type) {
        expect(fn () => loanService()->takeBack($handler, $asset->fresh(), $loan, $line, $type, '1.000000', 'GOOD', null))
            ->toThrow(LoanDomainException::class, __('loans.error.return_type_invalid'));
    }
    // An incident without a description (10+ characters) is refused, like a worse condition without a wear reason.
    foreach (['DAMAGED', 'LOST'] as $outcome) {
        loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'DAMAGED', 'note' => 'สั้นไป', 'outcome' => $outcome]])
            ->assertSessionHasErrors(['return' => __('loans.error.incident_description_required')]);
    }
    expect(loanCounterState($loan))->toBe($before);
    expect(LoanMovement::where('loan_asset_id', $asset->id)->whereIn('movement_type', ['RETURN_DAMAGED', 'MARK_LOST'])->count())->toBe(0);

    // Accepted since step 4, on the page (exact words) and in the service (case and blanks ignored): each is an
    // incident with its own movement type; none of it counts as "returned usable".
    foreach (['DAMAGED', 'LOST'] as $outcome) {
        loanReturnPost($this, $handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'DAMAGED', 'note' => WEAR_REASON, 'outcome' => $outcome]])
            ->assertRedirect(route('loans.show', $loan))->assertSessionHas('status', __('loans.returned_incident'));
    }
    foreach (['damaged', ' lost '] as $outcome) {
        app(LoanCirculationService::class)->receiveReturn($handler, $loan->fresh(), [$line->line_no => ['qty' => '1', 'condition' => 'DAMAGED', 'note' => WEAR_REASON, 'outcome' => $outcome]]);
    }

    $line = $line->fresh();
    expect([$line->qty_returned_ok, $line->qty_returned_damaged, $line->qty_lost, $line->outstanding()])->toBe(['0.000000', '2.000000', '2.000000', '0.000000']);
    expect(LoanMovement::where('loan_id', $loan->id)->orderBy('id')->pluck('movement_type')->all())
        ->toBe(['HANDOUT', 'RETURN_DAMAGED', 'MARK_LOST', 'RETURN_DAMAGED', 'MARK_LOST']);
    expect(LoanMovement::where('loan_id', $loan->id)->whereIn('movement_type', ['RETURN_DAMAGED', 'MARK_LOST'])->whereNull('incident_id')->count())->toBe(0);
    $a = $asset->fresh();
    expect([$a->qty_on_loan, $a->qty_unavailable, $a->qty_total])->toBe(['0.000000', '4.000000', '10.000000']);
    // Nothing is with the borrower, yet the document is not CLOSED while the incidents wait (BR-L12; LoanIncidentTest).
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED')->and($loan->fresh()->closed_at)->toBeNull();
    assertLoanChainIntact($a);
    $this->artisan('loans:verify')->assertExitCode(0);
});

test('a loan cannot be cancelled once it was handed out — by the borrower, by an approver of the branch, in any later status', function (string $status) {
    [$lab, $asset, $borrower, $handler] = counterScene();
    $loan = handedOutLoan($borrower, $asset, 2);
    if ($status === 'PARTIALLY_RETURNED') {
        app(LoanCirculationService::class)->receiveReturn($handler, $loan, [1 => ['qty' => '1', 'condition' => 'GOOD']]);
    } elseif ($status === 'CLOSED') {
        app(LoanCirculationService::class)->receiveReturn($handler, $loan, [1 => ['qty' => '2', 'condition' => 'GOOD']]);
    }
    expect($loan->fresh()->status)->toBe($status);
    $approver = auditorUser(['lab_id' => $lab->id]);
    $before = loanCounterState($loan);

    loanCancelPost($this, $borrower, $loan, ['reason' => 'ขอยกเลิกหลังรับของ'])->assertForbidden();
    loanCancelPost($this, $approver, $loan, ['reason' => 'ขอยกเลิกหลังรับของ'])->assertForbidden();
    foreach ([$borrower, $approver] as $actor) {
        expect(fn () => app(LoanService::class)->cancel($actor->fresh(), $loan, 'ขอยกเลิกหลังรับของ'))
            ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));
    }

    expect(loanCounterState($loan))->toBe($before);
    expect($loan->fresh()->cancelled_at)->toBeNull();
    expect(loanAudit('LOAN_CANCEL', $loan))->toHaveCount(0);
})->with(['ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED']);

test('the ledger refuses a hand-out / return whose loan, line and asset do not belong together', function () {
    [$lab, $asset, $borrower, $handler] = counterScene();
    $otherAsset = loanExtraAsset($lab, 5);
    $loan = approvedLoan($borrower, $asset, 1);
    $otherLoan = approvedLoan($borrower, $otherAsset, 1);
    $line = $loan->lines()->firstOrFail();
    $otherLine = $otherLoan->lines()->firstOrFail();

    // A line of another loan; an asset that is not the line's asset.
    expect(fn () => loanService()->handOut($handler, $asset->fresh(), $loan, $otherLine, '1.000000', 'GOOD', null))
        ->toThrow(LoanDomainException::class, __('loans.error.line_mismatch'));
    expect(fn () => loanService()->handOut($handler, $otherAsset->fresh(), $loan, $line, '1.000000', 'GOOD', null))
        ->toThrow(LoanDomainException::class, __('loans.error.line_mismatch'));
    // Not a whole quantity, not a condition.
    expect(fn () => loanService()->handOut($handler, $asset->fresh(), $loan, $line, '0.500000', 'GOOD', null))
        ->toThrow(LoanDomainException::class, __('loans.error.qty_whole'));
    expect(fn () => loanService()->handOut($handler, $asset->fresh(), $loan, $line, '1.000000', 'NEW', null))
        ->toThrow(LoanDomainException::class, __('loans.error.condition_required'));

    expect(LoanMovement::whereIn('movement_type', ['HANDOUT', 'RETURN_OK'])->count())->toBe(0);
    expect($asset->fresh()->qty_on_loan)->toBe('0.000000')->and($otherAsset->fresh()->qty_on_loan)->toBe('0.000000');
});

test('while pieces are out the asset cannot be retired, and a pool cannot be adjusted below what is out', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 5);
    $loan = approvedLoan(staffUser(['lab_id' => $lab->id]), $asset, 4, $approver);
    app(LoanCirculationService::class)->handOut($manager, $loan, loanConditions($loan));

    expect(fn () => loanService()->retire($manager, $asset->fresh(), 'ปลดระวางระหว่างมีของถูกยืม', $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.retire_while_on_loan'));
    expect(fn () => loanService()->adjust($manager, $asset->fresh(), 'OUT', 2, 'นับใหม่แล้วพบว่าขาดไปสองชิ้น', 'REF-12345', $approver->id))
        ->toThrow(LoanDomainException::class, __('loans.error.adjust_out_too_much'));

    expect($asset->fresh()->qty_total)->toBe('5.000000')->and($asset->fresh()->qty_on_loan)->toBe('4.000000')->and($asset->fresh()->status)->toBe('ACTIVE');
});

// --- loans:verify, part (c) ------------------------------------------------------------------------------------------

test('loans:verify (c) passes while loans are APPROVED, out, partly back and closed', function () {
    [$lab, $asset, $borrower, $handler] = counterScene(10);
    approvedLoan($borrower, $asset, 2);                                   // APPROVED: nothing out yet
    handedOutLoan($borrower, $asset, 3);                                  // ON_LOAN: 3
    $part = handedOutLoan($borrower, $asset, 2);
    app(LoanCirculationService::class)->receiveReturn($handler, $part, [1 => ['qty' => '1', 'condition' => 'GOOD']]);   // 1 left
    $closed = handedOutLoan($borrower, $asset, 1);
    app(LoanCirculationService::class)->receiveReturn($handler, $closed, [1 => ['qty' => '1', 'condition' => 'GOOD']]);

    expect($asset->fresh()->qty_on_loan)->toBe('4.000000');
    $this->artisan('loans:verify')->expectsOutputToContain(__('loans.verify_cmd.ok', ['count' => 1]))->assertExitCode(0);
    expect($lab->id)->toBe($asset->lab_id);
});

test('loans:verify (c) catches a loan document edited by hand: the asset still equals its ledger, but not the open documents', function (string $tamper) {
    [, $asset, $borrower] = counterScene(10);
    $loan = handedOutLoan($borrower, $asset, 3);
    $line = $loan->lines()->firstOrFail();
    $this->artisan('loans:verify')->assertExitCode(0);

    match ($tamper) {
        // "Two came back" written on the line without any return movement.
        'a line says more came back' => DB::table('loan_lines')->where('id', $line->id)->update(['qty_returned_ok' => 2]),
        // The document closed by hand while the pieces are still counted as out.
        'the loan closed by hand' => DB::table('loans')->where('id', $loan->id)->update(['status' => 'CLOSED']),
        // The document pushed back to APPROVED: the hand-out "never happened".
        'the loan set back to APPROVED' => DB::table('loans')->where('id', $loan->id)->update(['status' => 'APPROVED']),
        // The approved quantity cut after the hand-out.
        'the approved quantity lowered' => DB::table('loan_lines')->where('id', $line->id)->update(['qty_approved' => 1]),
    };

    $this->artisan('loans:verify')
        ->expectsOutputToContain(__('loans.verify_cmd.broken', ['problem' => "asset #{$asset->id}: on-loan quantity differs from the open loan documents"]))
        ->assertExitCode(1);
})->with(['a line says more came back', 'the loan closed by hand', 'the loan set back to APPROVED', 'the approved quantity lowered']);

test('loans:verify catches an on-loan counter edited by hand after a hand-out (both against the ledger and against the documents)', function () {
    [, $asset, $borrower] = counterScene(10);
    handedOutLoan($borrower, $asset, 3);
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 1]);   // "only one is out"

    $this->artisan('loans:verify')
        ->expectsOutputToContain("asset #{$asset->id}: on-loan quantity differs from the open loan documents")
        ->expectsOutputToContain('quantities differ')
        ->assertExitCode(1);
});

test('a HANDOUT row forged into the ledger (pieces "lent" with no document) is caught by loans:verify', function () {
    [$lab, $asset, , $handler] = counterScene(10);
    $last = LoanMovement::where('loan_asset_id', $asset->id)->orderByDesc('id')->firstOrFail();
    DB::table('loan_assets')->where('id', $asset->id)->update(['qty_on_loan' => 2]);
    $forged = new LoanMovement([
        'loan_asset_id' => $asset->id, 'lab_id' => $lab->id, 'movement_type' => 'HANDOUT', 'qty' => '2.000000',
        'qty_total_after' => '10.000000', 'qty_on_loan_after' => '2.000000', 'qty_unavailable_after' => '0.000000',
        'created_by' => $handler->id, 'created_at' => now(), 'prev_row_hash' => $last->row_hash,
    ]);
    $forged->row_hash = app(\App\Domain\Loan\Services\LoanLedgerHasher::class)->compute($forged);
    $forged->save();

    // The chain and the "latest movement" check are both satisfied; only (c) notices nobody holds these pieces.
    $this->artisan('loans:verify')
        ->expectsOutputToContain("asset #{$asset->id}: on-loan quantity differs from the open loan documents")
        ->assertExitCode(1);
});

// --- the models' own arithmetic ------------------------------------------------------------------------------------------

test('LoanLine::outstanding and Loan::isOverdue / daysOverdue / the overdue scope agree at the edges', function () {
    [, $asset, $borrower, $handler] = counterScene();
    $loan = handedOutLoan($borrower, $asset, 3);
    $line = $loan->lines()->firstOrFail();
    expect($line->qtyOut())->toBe('3.000000')->and($line->outstanding())->toBe('3.000000');

    // The due date itself is still in time.
    $this->travelTo($loan->due_date->copy()->setTime(23, 30));
    expect($loan->fresh()->isOverdue())->toBeFalse()->and($loan->fresh()->daysOverdue())->toBe(0);
    expect(Loan::query()->overdue()->whereKey($loan->id)->exists())->toBeFalse();

    $this->travelTo($loan->due_date->copy()->addDay()->setTime(0, 5));
    expect($loan->fresh()->isOverdue())->toBeTrue()->and($loan->fresh()->daysOverdue())->toBe(1);
    expect(Loan::query()->overdue()->whereKey($loan->id)->exists())->toBeTrue();
    expect(Loan::query()->overdue(1)->whereKey($loan->id)->exists())->toBeFalse();   // "more than 1 day" is not yet true

    $this->travelTo($loan->due_date->copy()->addDays(8)->setTime(12, 0));
    expect($loan->fresh()->daysOverdue())->toBe(8);
    expect(Loan::query()->overdue(7)->whereKey($loan->id)->exists())->toBeTrue();
    expect(Loan::query()->overdue(8)->whereKey($loan->id)->exists())->toBeFalse();

    // Partly back is still overdue; fully back is not, whatever the date.
    app(LoanCirculationService::class)->receiveReturn($handler, $loan, [$line->line_no => ['qty' => '1', 'condition' => 'GOOD']]);
    expect($loan->fresh()->status)->toBe('PARTIALLY_RETURNED')->and($loan->fresh()->isOverdue())->toBeTrue();
    app(LoanCirculationService::class)->receiveReturn($handler, $loan, [$line->line_no => ['qty' => '2', 'condition' => 'GOOD']]);
    expect($loan->fresh()->status)->toBe('CLOSED')->and($loan->fresh()->isOverdue())->toBeFalse()->and($loan->fresh()->daysOverdue())->toBe(0);
    expect(Loan::query()->overdue()->whereKey($loan->id)->exists())->toBeFalse();
    expect(LoanLine::findOrFail($line->id)->outstanding())->toBe('0.000000');
    expect(LoanAsset::findOrFail($asset->id)->qty_on_loan)->toBe('0.000000');
});
