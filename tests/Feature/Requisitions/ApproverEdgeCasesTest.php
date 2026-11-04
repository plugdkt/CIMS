<?php

use App\Domain\Notification\Services\NotificationService;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/* (Written without being run: the coordinator runs the suite.) */

test('a requester who is a scientist gets no "pending" notice for their own requisition, the other scientists of the branch still do', function () {
    Mail::fake();
    $lab = makeLab();
    $requester = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $other = scientistUser(['lab_id' => $lab->id]);
    $requisition = requisitionWithLines($requester, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    app(NotificationService::class)->notifyApprovalPending($requisition);

    expect(Notification::where('user_id', $requester->id)->where('type', 'requisition.pending_scientist')->count())->toBe(0);
    expect(Notification::where('user_id', $other->id)->where('type', 'requisition.pending_scientist')->count())->toBe(1);
    // ...but the requester is still an approver of it (self approval is allowed, and audited).
    expect(app(NotificationService::class)->approversFor($requisition)->pluck('id')->all())->toContain($requester->id, $other->id);
});

test('a requester who is the ONLY approver of the branch: nobody is told, and ADMIN gets no "no approver" fallback either (behaviour as coded)', function () {
    Mail::fake();
    $lab = makeLab();
    $requester = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $admin = adminUser();
    $requisition = requisitionWithLines($requester, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    app(NotificationService::class)->notifyApprovalPending($requisition);

    expect(Notification::count())->toBe(0);
    expect(Notification::where('user_id', $admin->id)->count())->toBe(0);
    Mail::assertNothingQueued();
});
