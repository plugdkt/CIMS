<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanState;

/*
 * ITM-2610-051 steps 2-3, spec 8.1: the loan document's state machine. Pure (no database).
 *
 * The allowed transitions are listed explicitly below; EVERY other combination of status x event x borrower
 * status must be refused. A STUDENT's loan never reaches a manager straight from SUBMITTED (BR-L03 / BR-02); after the
 * advisor it is APPROVED at once (CEO 2026-11-05, default config), ADVISOR_APPROVED remains only for legacy rows.
 * Combinations that cannot occur in real data are left out of the "must refuse" sweep on purpose (marked below):
 * a non-student in ADVISOR_APPROVED, and a null borrower status other than for submit / cancel (the snapshot
 * column is NOT NULL; BR-L14 maps NULL to STUDENT before it is stored).
 *
 * Step 3 added three events: `handout` (APPROVED -> ON_LOAN), `returnPartial` and `close` (from ON_LOAN /
 * PARTIALLY_RETURNED). They are in the allowed list below, so the "everything else is refused" sweep now also proves
 * that they work from nowhere else. `return` is NOT an event (it stays in the sweep as a word that must be refused).
 */

const LOAN_STATUSES = ['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED'];
const LOAN_EVENTS = ['submit', 'advisorApprove', 'advisorReject', 'managerApprove', 'managerReject', 'cancel', 'handout', 'returnPartial', 'close', 'return', 'bogus', ''];
const LOAN_BORROWERS = ['STUDENT', 'STAFF', 'LECTURER'];

/**
 * Every allowed transition as [status, event, borrower statuses it applies to, next status].
 *
 * @return list<array{0: string, 1: string, 2: list<string>, 3: string}>
 */
function loanAllowedTransitions(): array
{
    return [
        ['DRAFT', 'submit', LOAN_BORROWERS, 'SUBMITTED'],
        // CEO 2026-11-05: default student_needs_manager_step = false, so the advisor's approval is the last one.
        ['SUBMITTED', 'advisorApprove', ['STUDENT'], 'APPROVED'],
        ['SUBMITTED', 'advisorReject', ['STUDENT'], 'REJECTED'],
        ['SUBMITTED', 'managerApprove', ['STAFF', 'LECTURER'], 'APPROVED'],
        ['SUBMITTED', 'managerReject', ['STAFF', 'LECTURER'], 'REJECTED'],
        // Legacy rows (advisor signed before 2026-11-05) stay decidable by the branch approver.
        ['ADVISOR_APPROVED', 'managerApprove', ['STUDENT'], 'APPROVED'],
        ['ADVISOR_APPROVED', 'managerReject', ['STUDENT'], 'REJECTED'],
        ['DRAFT', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        ['SUBMITTED', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        ['ADVISOR_APPROVED', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        ['APPROVED', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        // Step 3: the counter. Every line goes out at once; returns may come in parts; closing ends the loan.
        ['APPROVED', 'handout', LOAN_BORROWERS, 'ON_LOAN'],
        ['ON_LOAN', 'returnPartial', LOAN_BORROWERS, 'PARTIALLY_RETURNED'],
        ['PARTIALLY_RETURNED', 'returnPartial', LOAN_BORROWERS, 'PARTIALLY_RETURNED'],
        ['ON_LOAN', 'close', LOAN_BORROWERS, 'CLOSED'],
        ['PARTIALLY_RETURNED', 'close', LOAN_BORROWERS, 'CLOSED'],
    ];
}

test('every allowed transition gives the next status', function () {
    $state = new LoanState();

    foreach (loanAllowedTransitions() as [$status, $event, $borrowers, $next]) {
        foreach ($borrowers as $borrower) {
            expect($state->apply($status, $event, $borrower))->toBe($next, "{$status} --{$event}--> for {$borrower}");
        }
    }
});

test('every combination that is not in the allowed list is refused', function () {
    $state = new LoanState();
    $allowed = [];
    foreach (loanAllowedTransitions() as [$status, $event, $borrowers]) {
        foreach ($borrowers as $borrower) {
            $allowed["{$status}|{$event}|{$borrower}"] = true;
        }
    }

    $checked = 0;
    foreach (LOAN_STATUSES as $status) {
        foreach (LOAN_EVENTS as $event) {
            foreach (LOAN_BORROWERS as $borrower) {
                if (isset($allowed["{$status}|{$event}|{$borrower}"])) {
                    continue;
                }
                // Not reachable in real data (see the file header): not pinned either way.
                if ($status === 'ADVISOR_APPROVED' && $borrower !== 'STUDENT') {
                    continue;
                }

                expect(fn () => $state->apply($status, $event, $borrower))
                    ->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(250);   // the sweep really ran (9 statuses x 12 events x 3 borrowers, minus the allowed ones)
});

test('step 3: hand-out only from APPROVED, returns only while things are out, and CLOSED is the end', function () {
    $state = new LoanState();

    foreach (array_diff(LOAN_STATUSES, ['APPROVED']) as $status) {
        expect(fn () => $state->apply($status, 'handout', 'STAFF'))->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));
    }
    foreach (array_diff(LOAN_STATUSES, ['ON_LOAN', 'PARTIALLY_RETURNED']) as $status) {
        foreach (['returnPartial', 'close'] as $event) {
            expect(fn () => $state->apply($status, $event, 'STAFF'))->toThrow(LoanDomainException::class, __('loans.error.invalid_transition'));
        }
    }
    // Nothing at all leaves CLOSED (nor REJECTED / CANCELLED).
    foreach (['CLOSED', 'REJECTED', 'CANCELLED'] as $end) {
        foreach (LOAN_EVENTS as $event) {
            foreach (LOAN_BORROWERS as $borrower) {
                expect(fn () => $state->apply($end, $event, $borrower))->toThrow(LoanDomainException::class);
            }
        }
    }
    // The counter's events do not depend on who borrowed, an unknown borrower status included.
    expect($state->apply('APPROVED', 'handout'))->toBe('ON_LOAN');
    expect($state->apply('ON_LOAN', 'returnPartial', null))->toBe('PARTIALLY_RETURNED');
    expect($state->apply('PARTIALLY_RETURNED', 'close', null))->toBe('CLOSED');
    // A legacy ADVISOR_APPROVED row was never approved by the branch: it cannot be handed out.
    expect(fn () => $state->apply('ADVISOR_APPROVED', 'handout', 'STUDENT'))->toThrow(LoanDomainException::class);
});

test('after hand-out (or at the end of a loan) nothing in step 2 can move the document, cancel included', function () {
    $state = new LoanState();

    foreach (['ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED'] as $status) {
        foreach (['submit', 'advisorApprove', 'advisorReject', 'managerApprove', 'managerReject', 'cancel'] as $event) {
            expect(fn () => $state->apply($status, $event, 'STAFF'))->toThrow(LoanDomainException::class);
            expect(fn () => $state->apply($status, $event, 'STUDENT'))->toThrow(LoanDomainException::class);
        }
    }
});

test('a STUDENT cannot skip the advisor: no manager decision from SUBMITTED, and a non-student has no advisor step', function () {
    $state = new LoanState();

    expect(fn () => $state->apply('SUBMITTED', 'managerApprove', 'STUDENT'))->toThrow(LoanDomainException::class);
    expect(fn () => $state->apply('SUBMITTED', 'managerReject', 'STUDENT'))->toThrow(LoanDomainException::class);
    expect(fn () => $state->apply('SUBMITTED', 'advisorApprove', 'STAFF'))->toThrow(LoanDomainException::class);
    expect(fn () => $state->apply('SUBMITTED', 'advisorReject', 'LECTURER'))->toThrow(LoanDomainException::class);
    // An unknown borrower status is not a student either, so it cannot take the advisor step.
    expect(fn () => $state->apply('SUBMITTED', 'advisorApprove'))->toThrow(LoanDomainException::class);
});

test('submit and cancel do not depend on the borrower status, including an unknown one', function () {
    $state = new LoanState();

    expect($state->apply('DRAFT', 'submit'))->toBe('SUBMITTED');
    expect($state->apply('DRAFT', 'cancel'))->toBe('CANCELLED');
    expect($state->apply('SUBMITTED', 'cancel', null))->toBe('CANCELLED');
});

test('student second step switch: false (default, CEO 2026-11-05) goes straight to APPROVED, true restores ADVISOR_APPROVED', function () {
    $state = new LoanState();

    // CEO decided 2026-11-05 (approvals.md): no branch step for a student's loan. This pins the shipped default;
    // flipping config/loans.php `student_needs_manager_step` must flip this test together with it.
    expect(config('loans.student_needs_manager_step'))->toBeFalse();
    expect($state->studentNeedsManagerStep())->toBeFalse();
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('APPROVED');

    config(['loans.student_needs_manager_step' => true]);
    expect($state->studentNeedsManagerStep())->toBeTrue();
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('ADVISOR_APPROVED');
    // A rejection and every non-student path are untouched by the switch.
    expect($state->apply('SUBMITTED', 'advisorReject', 'STUDENT'))->toBe('REJECTED');
    expect($state->apply('SUBMITTED', 'managerApprove', 'STAFF'))->toBe('APPROVED');
    expect(fn () => $state->apply('SUBMITTED', 'managerApprove', 'STUDENT'))->toThrow(LoanDomainException::class);

    config(['loans.student_needs_manager_step' => false]);
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('APPROVED');
});

test('a legacy ADVISOR_APPROVED student row is still decidable by the manager whatever the switch says', function () {
    $state = new LoanState();

    foreach ([false, true] as $switch) {
        config(['loans.student_needs_manager_step' => $switch]);
        expect($state->apply('ADVISOR_APPROVED', 'managerApprove', 'STUDENT'))->toBe('APPROVED');
        expect($state->apply('ADVISOR_APPROVED', 'managerReject', 'STUDENT'))->toBe('REJECTED');
        expect($state->apply('ADVISOR_APPROVED', 'cancel', 'STUDENT'))->toBe('CANCELLED');
    }
});
