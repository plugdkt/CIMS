<?php

use App\Domain\Requisition\Exceptions\InvalidApprovalDecisionException;
use App\Domain\Requisition\Services\ApprovalService;
use App\Models\RequisitionApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);



test('the requisition\'s own advisor can approve a SUBMITTED STUDENT requisition', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $advisor = $requisition->advisor;

    $service = app(ApprovalService::class);
    $result = $service->advisorDecide($requisition, $advisor, 'APPROVE');

    expect($result->status)->toBe('ADVISOR_APPROVED');
    expect($result->advisor_signed_at)->not->toBeNull();
    expect($result->advisor_signature_hash)->not->toBeNull();
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->where('step', 'ADVISOR')->first()->decision)->toBe('APPROVE');
});

test('the advisor can reject with a reason, ending the requisition', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $advisor = $requisition->advisor;

    $service = app(ApprovalService::class);
    $result = $service->advisorDecide($requisition, $advisor, 'REJECT', 'เอกสารไม่ครบถ้วน');

    expect($result->status)->toBe('REJECTED');
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->first()->reason)->toBe('เอกสารไม่ครบถ้วน');
});

test('the advisor cannot reject without a reason', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $advisor = $requisition->advisor;

    expect(fn () => app(ApprovalService::class)->advisorDecide($requisition, $advisor, 'REJECT'))
        ->toThrow(InvalidApprovalDecisionException::class);
});

test('an advisor who is not the requisition\'s own advisor cannot decide it', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $otherAdvisor = User::factory()->create(['person_type' => 'LECTURER']);
    $otherAdvisor->roles()->attach(\App\Models\Role::where('code', 'ADVISOR')->firstOrFail());

    expect(fn () => app(ApprovalService::class)->advisorDecide($requisition, $otherAdvisor, 'APPROVE'))
        ->toThrow(InvalidApprovalDecisionException::class);
});

test('FT-01: the scientist cannot approve a STUDENT requisition with no advisor sign-off yet (BR-02)', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student); // still SUBMITTED, advisor_signed_at is NULL
    $scientist = scientistUser();

    expect(fn () => app(ApprovalService::class)->scientistDecide($requisition, $scientist, 'APPROVE'))
        ->toThrow(InvalidApprovalDecisionException::class, 'BR-02');

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('SUBMITTED');
    expect($fresh->scientist_id)->toBeNull();
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->count())->toBe(0);
});

test('BR-02: the scientist cannot reject a STUDENT requisition before the advisor has acted either', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $scientist = scientistUser();

    expect(fn () => app(ApprovalService::class)->scientistDecide($requisition, $scientist, 'REJECT', 'ไม่จำเป็น'))
        ->toThrow(InvalidApprovalDecisionException::class, 'BR-02');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('BR-02 is checked against advisor_signed_at itself, not just the status string', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $requisition->update(['status' => 'ADVISOR_APPROVED']); // inconsistent data: no advisor_signed_at
    $scientist = scientistUser();

    expect(fn () => app(ApprovalService::class)->scientistDecide($requisition->fresh(), $scientist, 'APPROVE'))
        ->toThrow(InvalidApprovalDecisionException::class, 'BR-02');

    expect($requisition->fresh()->status)->toBe('ADVISOR_APPROVED');
});

test('an advisor rejection ends a STUDENT requisition, so the scientist can never approve it', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    app(ApprovalService::class)->advisorDecide($requisition, $requisition->advisor, 'REJECT', 'ไม่เหมาะสม');

    expect(fn () => app(ApprovalService::class)->scientistDecide($requisition->fresh(), scientistUser(), 'APPROVE'))
        ->toThrow(InvalidApprovalDecisionException::class);
    expect($requisition->fresh()->status)->toBe('REJECTED');
});

test('the scientist can approve once the advisor has signed off (BR-02 satisfied)', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $advisor = $requisition->advisor;
    app(ApprovalService::class)->advisorDecide($requisition, $advisor, 'APPROVE');

    $scientist = scientistUser();
    $result = app(ApprovalService::class)->scientistDecide($requisition->fresh(), $scientist, 'APPROVE');

    expect($result->status)->toBe('APPROVED');
    expect($result->scientist_id)->toBe($scientist->id);
    expect($result->scientist_decision)->toBe('APPROVE');
});

test('a non-student (STAFF) requisition can be approved by the scientist straight from SUBMITTED', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    $result = app(ApprovalService::class)->scientistDecide($requisition, $scientist, 'APPROVE');

    expect($result->status)->toBe('APPROVED');
});

test('FT-06: the scientist cannot reject without a reason', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    expect(fn () => app(ApprovalService::class)->scientistDecide($requisition, $scientist, 'REJECT'))
        ->toThrow(InvalidApprovalDecisionException::class);
});

test('the scientist rejecting with a reason stores it on the requisition and ends it', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    $result = app(ApprovalService::class)->scientistDecide($requisition, $scientist, 'REJECT', 'ไม่มีความจำเป็นเร่งด่วน');

    expect($result->status)->toBe('REJECTED');
    expect($result->reject_reason)->toBe('ไม่มีความจำเป็นเร่งด่วน');
});
