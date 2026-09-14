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

test('a non-student requester can go straight from SUBMITTED to a scientist decision (BR-01 "SUBMITTED(non-student)")', function () {
    $state = new RequisitionState();

    expect($state->apply('SUBMITTED', 'scientistApprove', 'STAFF'))->toBe('APPROVED');
    expect($state->apply('SUBMITTED', 'scientistApprove', 'LECTURER'))->toBe('APPROVED');
    expect($state->apply('SUBMITTED', 'scientistReject', 'STAFF'))->toBe('REJECTED');
});

test('in working stock, any requester (including STUDENT) can go straight from SUBMITTED to a scientist decision', function () {
    $state = new RequisitionState();

    expect($state->can('SUBMITTED', 'scientistApprove', 'STUDENT'))->toBeTrue();
    expect($state->can('SUBMITTED', 'scientistReject', 'STUDENT'))->toBeTrue();
    expect($state->apply('SUBMITTED', 'scientistApprove', 'STUDENT'))->toBe('APPROVED');
    expect($state->apply('SUBMITTED', 'scientistReject', 'STUDENT'))->toBe('REJECTED');
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
