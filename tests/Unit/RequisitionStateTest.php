<?php

use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\RequisitionState;

test('DRAFT can be submitted or cancelled (BR-01)', function () {
    $state = new RequisitionState();

    expect($state->apply('DRAFT', 'submit'))->toBe('SUBMITTED');
    expect($state->apply('DRAFT', 'cancel'))->toBe('CANCELLED');
});

test('SUBMITTED goes through the advisor step or is cancelled (BR-01)', function () {
    $state = new RequisitionState();

    expect($state->apply('SUBMITTED', 'advisorApprove'))->toBe('ADVISOR_APPROVED');
    expect($state->apply('SUBMITTED', 'advisorReject'))->toBe('REJECTED');
    expect($state->apply('SUBMITTED', 'cancel'))->toBe('CANCELLED');
});

test('approvals.md K: a STUDENT requisition is APPROVED by the advisor alone (the scientist step is skipped for students only)', function () {
    $state = new RequisitionState();

    expect($state->apply('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBe('APPROVED');
    expect($state->can('SUBMITTED', 'advisorApprove', 'STUDENT'))->toBeTrue();
    // The advisor's reject still ends it.
    expect($state->apply('SUBMITTED', 'advisorReject', 'STUDENT'))->toBe('REJECTED');
    // A non-student that happens to have an advisor keeps the two-step path.
    expect($state->apply('SUBMITTED', 'advisorApprove', 'STAFF'))->toBe('ADVISOR_APPROVED');
    expect($state->apply('SUBMITTED', 'advisorApprove', 'LECTURER'))->toBe('ADVISOR_APPROVED');
});

test('approvals.md K: once a student requisition is APPROVED the scientist decision events no longer apply to it', function () {
    $state = new RequisitionState();

    expect($state->can('APPROVED', 'scientistApprove', 'STUDENT'))->toBeFalse();
    expect($state->can('APPROVED', 'scientistReject', 'STUDENT'))->toBeFalse();
    expect(fn () => $state->apply('APPROVED', 'scientistApprove', 'STUDENT'))
        ->toThrow(InvalidRequisitionTransitionException::class);
});

test('a non-student requester can go straight from SUBMITTED to a scientist decision (BR-01 "SUBMITTED(non-student)")', function () {
    $state = new RequisitionState();

    expect($state->apply('SUBMITTED', 'scientistApprove', 'STAFF'))->toBe('APPROVED');
    expect($state->apply('SUBMITTED', 'scientistApprove', 'LECTURER'))->toBe('APPROVED');
    expect($state->apply('SUBMITTED', 'scientistReject', 'STAFF'))->toBe('REJECTED');
});

test('a STUDENT requester cannot skip the advisor step for a scientist decision (BR-02)', function () {
    $state = new RequisitionState();

    expect($state->can('SUBMITTED', 'scientistApprove', 'STUDENT'))->toBeFalse();
    expect($state->can('SUBMITTED', 'scientistReject', 'STUDENT'))->toBeFalse();
    expect($state->can('SUBMITTED', 'scientistApprove', null))->toBeFalse();

    expect(fn () => $state->apply('SUBMITTED', 'scientistApprove', 'STUDENT'))
        ->toThrow(InvalidRequisitionTransitionException::class);
    expect(fn () => $state->apply('SUBMITTED', 'scientistReject', 'STUDENT'))
        ->toThrow(InvalidRequisitionTransitionException::class);
});

test('ADVISOR_APPROVED moves to a scientist decision regardless of requester status', function () {
    $state = new RequisitionState();

    expect($state->apply('ADVISOR_APPROVED', 'scientistApprove'))->toBe('APPROVED');
    expect($state->apply('ADVISOR_APPROVED', 'scientistReject'))->toBe('REJECTED');
});

test('APPROVED and PARTIALLY_ISSUED move toward ISSUED as items are issued (BR-01)', function () {
    $state = new RequisitionState();

    expect($state->apply('APPROVED', 'issuePartial'))->toBe('PARTIALLY_ISSUED');
    expect($state->apply('APPROVED', 'issueFull'))->toBe('ISSUED');
    expect($state->apply('PARTIALLY_ISSUED', 'issuePartial'))->toBe('PARTIALLY_ISSUED');
    expect($state->apply('PARTIALLY_ISSUED', 'issueFull'))->toBe('ISSUED');
});

test('REJECTED, ISSUED and CANCELLED are terminal — no event moves them anywhere', function () {
    $state = new RequisitionState();

    foreach (['REJECTED', 'ISSUED', 'CANCELLED'] as $terminal) {
        foreach (['submit', 'advisorApprove', 'advisorReject', 'scientistApprove', 'scientistReject', 'issuePartial', 'issueFull', 'cancel'] as $event) {
            expect($state->can($terminal, $event))->toBeFalse();
        }
    }
});

test('an event not valid for the current state throws', function () {
    $state = new RequisitionState();

    expect(fn () => $state->apply('DRAFT', 'advisorApprove'))
        ->toThrow(InvalidRequisitionTransitionException::class);
    expect(fn () => $state->apply('APPROVED', 'submit'))
        ->toThrow(InvalidRequisitionTransitionException::class);
});
