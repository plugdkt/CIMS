<?php

use App\Domain\Requisition\Services\ApprovalService;
use App\Domain\Requisition\Services\RequisitionService;
use App\Mail\AdvisorApprovalMail;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function withOneLine(\App\Models\Requisition $requisition): \App\Models\Requisition
{
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requisition->items()->create([
        'line_no' => 1,
        'item_id' => $item->id,
        'qty_requested' => '5.000000',
        'unit_id' => $g->id,
        'qty_requested_base' => '5.000000',
    ]);

    return $requisition->fresh();
}

test('FR-NT-03: submitting a STUDENT requisition notifies the advisor in-app (email already sent by T-033)', function () {
    Mail::fake();
    $student = studentUser();
    $requisition = withOneLine(makeRequisition($student));

    app(RequisitionService::class)->submit($requisition);

    $advisor = $requisition->fresh()->advisor;
    $notification = Notification::where('user_id', $advisor->id)->where('type', 'requisition.pending_advisor')->first();
    expect($notification)->not->toBeNull();

    Mail::assertQueued(AdvisorApprovalMail::class);
    Mail::assertNotQueued(NotificationMail::class); // no duplicate generic email for the advisor step
});

test('FR-NT-03 (updated 2026-09-21, per-kind since ITM-2610-052 phase 3): submitting a non-student requisition notifies the warehouse managers of its own branch in-app and by email, and nobody elsewhere', function () {
    Mail::fake();
    $lab = makeLab();
    $staff = staffUser(['lab_id' => $lab->id]);
    $auditor = auditorUser(['lab_id' => $lab->id]);
    $labManager = labManagerUser(['lab_id' => $lab->id]);
    $otherBranch = auditorUser(['lab_id' => makeLab()->id]);   // holds both kinds, but another branch
    $requisition = withOneLine(makeRequisition($staff));       // one chemical line

    app(RequisitionService::class)->submit($requisition);

    expect(Notification::where('user_id', $auditor->id)->where('type', 'requisition.pending_scientist')->exists())->toBeTrue();
    expect(Notification::where('user_id', $labManager->id)->where('type', 'requisition.pending_scientist')->exists())->toBeTrue();
    expect(Notification::where('user_id', $otherBranch->id)->exists())->toBeFalse();
    Mail::assertQueued(NotificationMail::class, 2);
});

test('FR-NT-03 phase 3: with no responsible manager in the branch, the pending notice goes to ADMIN (requisition.no_approver) instead of broadcasting to other branches', function () {
    Mail::fake();
    $lab = makeLab();
    $staff = staffUser(['lab_id' => $lab->id]);
    $admin = adminUser();
    $elsewhere = auditorUser(['lab_id' => makeLab()->id]);
    $requisition = withOneLine(makeRequisition($staff));

    app(RequisitionService::class)->submit($requisition);

    expect(Notification::where('user_id', $admin->id)->where('type', 'requisition.no_approver')->exists())->toBeTrue();
    expect(Notification::where('type', 'requisition.pending_scientist')->exists())->toBeFalse();
    expect(Notification::where('user_id', $elsewhere->id)->exists())->toBeFalse();
});

test('FR-NT-04 + FR-NT-03 (updated 2026-09-21): an advisor approving notifies the requester of the result and the warehouse managers of its branch that it is now pending', function () {
    Mail::fake();
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $auditor = auditorUser(['lab_id' => $lab->id]);
    $otherBranch = auditorUser(['lab_id' => makeLab()->id]);
    $requisition = withOneLine(makeRequisition($student, ['status' => 'SUBMITTED', 'submitted_at' => now()]));
    $advisor = $requisition->advisor;

    app(ApprovalService::class)->advisorDecide($requisition, $advisor, 'APPROVE');

    expect(Notification::where('user_id', $student->id)->where('type', 'requisition.decision')->exists())->toBeTrue();
    expect(Notification::where('user_id', $auditor->id)->where('type', 'requisition.pending_scientist')->exists())->toBeTrue();
    expect(Notification::where('user_id', $otherBranch->id)->where('type', 'requisition.pending_scientist')->exists())->toBeFalse();
});

test('FR-NT-04: an advisor rejecting notifies the requester but not the scientist pool', function () {
    Mail::fake();
    $student = studentUser();
    $scientist = scientistUser();
    $requisition = withOneLine(makeRequisition($student, ['status' => 'SUBMITTED', 'submitted_at' => now()]));
    $advisor = $requisition->advisor;

    app(ApprovalService::class)->advisorDecide($requisition, $advisor, 'REJECT', 'ไม่เหมาะสมกับหัวข้อวิจัย');

    expect(Notification::where('user_id', $student->id)->where('type', 'requisition.decision')->exists())->toBeTrue();
    expect(Notification::where('user_id', $scientist->id)->where('type', 'requisition.pending_scientist')->exists())->toBeFalse();
});

test('FR-NT-04: a scientist decision (approve or reject) notifies the requester', function () {
    Mail::fake();
    $staff = staffUser();
    $scientist = scientistUser();
    $requisition = withOneLine(makeRequisition($staff, ['status' => 'SUBMITTED', 'submitted_at' => now()]));

    app(ApprovalService::class)->scientistDecide($requisition, $scientist, 'REJECT', 'สารเคมีหมด');

    $notification = Notification::where('user_id', $staff->id)->where('type', 'requisition.decision')->first();
    expect($notification)->not->toBeNull();
    expect($notification->title)->toContain($requisition->doc_no);
});

test('BR-02: submitting a STUDENT requisition does not notify warehouse managers yet (only after the advisor approves)', function () {
    Mail::fake();
    $student = studentUser();
    $auditor = auditorUser();
    $requisition = withOneLine(makeRequisition($student));

    app(RequisitionService::class)->submit($requisition);

    expect(Notification::where('user_id', $auditor->id)->where('type', 'requisition.pending_scientist')->exists())->toBeFalse();
});
