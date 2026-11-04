<?php

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanState;

/*
 * ITM-2610-051 step 2, spec 8.1: the loan document's state machine. Pure (no database).
 *
 * The allowed transitions are listed explicitly below; EVERY other combination of status x event x borrower
 * status must be refused. A STUDENT's loan never reaches a manager straight from SUBMITTED (BR-L03 / BR-02).
 * Combinations that cannot occur in real data are left out of the "must refuse" sweep on purpose (marked below):
 * a non-student in ADVISOR_APPROVED, and a null borrower status other than for submit / cancel (the snapshot
 * column is NOT NULL; BR-L14 maps NULL to STUDENT before it is stored).
 */

const LOAN_STATUSES = ['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'ON_LOAN', 'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED'];
const LOAN_EVENTS = ['submit', 'advisorApprove', 'advisorReject', 'managerApprove', 'managerReject', 'cancel', 'handout', 'return', 'bogus', ''];
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
        ['SUBMITTED', 'advisorApprove', ['STUDENT'], 'ADVISOR_APPROVED'],
        ['SUBMITTED', 'advisorReject', ['STUDENT'], 'REJECTED'],
        ['SUBMITTED', 'managerApprove', ['STAFF', 'LECTURER'], 'APPROVED'],
        ['SUBMITTED', 'managerReject', ['STAFF', 'LECTURER'], 'REJECTED'],
        ['ADVISOR_APPROVED', 'managerApprove', ['STUDENT'], 'APPROVED'],
        ['ADVISOR_APPROVED', 'managerReject', ['STUDENT'], 'REJECTED'],
        ['DRAFT', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        ['SUBMITTED', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        ['ADVISOR_APPROVED', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
        ['APPROVED', 'cancel', LOAN_BORROWERS, 'CANCELLED'],
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

    expect($checked)->toBeGreaterThan(200);   // the sweep really ran
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

test('student second step switch: true (default, BR-L03) goes to ADVISOR_APPROVED, false goes straight to APPROVED', function () {
    $state = new LoanState();

    // The CEO answer is still pending (approvals.md K). This pins the shipped default; flipping
    // config/loans.php `student_needs_manager_step` must flip this test together with it.
    expect(config('loans.student_needs_manager_step'))->toBeTrue();
    expect($state->studentNeedsManagerStep())->toBeTrue();
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('ADVISOR_APPROVED');

    config(['loans.student_needs_manager_step' => false]);
    expect($state->studentNeedsManagerStep())->toBeFalse();
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('APPROVED');
    // A rejection and every non-student path are untouched by the switch.
    expect($state->apply('SUBMITTED', 'advisorReject', 'STUDENT'))->toBe('REJECTED');
    expect($state->apply('SUBMITTED', 'managerApprove', 'STAFF'))->toBe('APPROVED');
    expect(fn () => $state->apply('SUBMITTED', 'managerApprove', 'STUDENT'))->toThrow(LoanDomainException::class);

    config(['loans.student_needs_manager_step' => true]);
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('ADVISOR_APPROVED');
});
