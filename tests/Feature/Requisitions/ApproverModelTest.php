<?php

use App\Domain\Requisition\Exceptions\InvalidApprovalDecisionException;
use App\Domain\Requisition\Services\ApprovalService;
use App\Domain\Requisition\Services\IssueService;
use App\Mail\NotificationMail;
use App\Models\AuditLog;
use App\Models\IssueTransaction;
use App\Models\Requisition;
use App\Models\RequisitionApproval;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * approvals.md K (CEO, 2026-11-04): who may approve and who may issue a requisition.
 *
 *   - every SCIENTIST of the requisition's own branch may approve it (any kind of stock);
 *   - LAB_MANAGER / AUDITOR may approve AND issue, narrowed to the kinds they hold (user_item_types);
 *   - the branch is checked for EVERY holder (a missing lab_id never matches, NULL is not equal to NULL);
 *   - ADMIN approves and issues nothing;
 *   - approving / issuing one's own requisition is allowed but leaves an audit row (REQ_SELF_*);
 *   - a STUDENT requisition is APPROVED by the advisor alone and goes straight to issue;
 *   - two approvers pressing the button at once: the first one wins.
 *
 * (Written without being run: see the report to the secretary.)
 */

if (! function_exists('approverReq')) {
    /** A SUBMITTED single-line requisition by STAFF of the given branch, one line of the given category code. */
    function approverReq(\App\Models\Lab $lab, string $category = 'CHEMICAL', array $overrides = []): Requisition
    {
        return requisitionWithLines(
            staffUser(['lab_id' => $lab->id]),
            [$category],
            array_merge(['lab_id' => $lab->id, 'status' => 'SUBMITTED', 'submitted_at' => now()], $overrides),
        );
    }
}

if (! function_exists('approverDecide')) {
    function approverDecide($test, \App\Models\User $user, Requisition $requisition, array $payload = ['decision' => 'APPROVE'])
    {
        return $test->actingAs($user)->post(route('requisitions.scientist-decide', $requisition), $payload);
    }
}

// --- who may approve ---------------------------------------------------------------------------------------------

test('a scientist of the requisition\'s own branch approves it, whatever the kind of stock (chemical, consumable, mixed)', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);

    foreach ([['CHEMICAL'], ['MATERIAL'], ['CHEMICAL', 'GLASSWARE']] as $categories) {
        $requisition = requisitionWithLines(staffUser(['lab_id' => $lab->id]), $categories, ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

        approverDecide($this, $scientist, $requisition)->assertRedirect(route('requisitions.show', $requisition));

        $fresh = $requisition->fresh();
        expect($fresh->status)->toBe('APPROVED', implode('+', $categories));
        expect($fresh->scientist_id)->toBe($scientist->id);
        expect(RequisitionApproval::where('requisition_id', $requisition->id)->where('step', 'SCIENTIST')->where('actor_id', $scientist->id)->count())->toBe(1);
    }
});

test('a scientist can reject too, with a reason; without one it is refused and nothing changes', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $requisition = approverReq($lab);

    approverDecide($this, $scientist, $requisition, ['decision' => 'REJECT'])->assertSessionHasErrors('reason');
    expect($requisition->fresh()->status)->toBe('SUBMITTED');

    approverDecide($this, $scientist, $requisition, ['decision' => 'REJECT', 'reason' => 'ของหมดชั่วคราว'])->assertRedirect(route('requisitions.show', $requisition));
    expect($requisition->fresh()->status)->toBe('REJECTED');
    expect($requisition->fresh()->reject_reason)->toBe('ของหมดชั่วคราว');
});

test('cross-branch: a scientist (or manager) of another branch is refused (403) and nothing is written', function () {
    $lab = makeLab();
    $requisition = approverReq($lab);
    $otherLab = makeLab();

    foreach ([scientistUser(['lab_id' => $otherLab->id]), auditorUser(['lab_id' => $otherLab->id]), labManagerUser(['lab_id' => $otherLab->id])] as $outsider) {
        approverDecide($this, $outsider, $requisition)->assertForbidden();
        approverDecide($this, $outsider, $requisition, ['decision' => 'REJECT', 'reason' => 'ไม่เห็นควร'])->assertForbidden();
    }

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('SUBMITTED')->and($fresh->scientist_id)->toBeNull();
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->count())->toBe(0);
    expect(AuditLog::where('entity_type', 'requisition')->where('entity_id', $requisition->id)->whereIn('action', ['REQ_APPROVE', 'REQ_REJECT', 'REQ_SELF_APPROVE', 'REQ_SELF_REJECT'])->count())->toBe(0);
});

test('empty lab_id: a scientist or manager with no branch can decide nothing, and NULL never "matches" a requisition without a branch', function () {
    $lab = makeLab();
    $requisition = approverReq($lab);

    foreach ([scientistUser(['lab_id' => null]), auditorUser(['lab_id' => null]), labManagerUser(['lab_id' => null])] as $noBranch) {
        approverDecide($this, $noBranch, $requisition)->assertForbidden();
        expect($noBranch->can('scientistDecide', $requisition))->toBeFalse();
    }
    expect($requisition->fresh()->status)->toBe('SUBMITTED');

    // NULL === NULL must not pass: even a requisition whose own lab_id is (in memory) null is not decidable by a user with no branch.
    $orphan = $requisition->replicate();
    $orphan->lab_id = null;
    $orphan->status = 'SUBMITTED';
    expect(scientistUser(['lab_id' => null])->can('scientistDecide', $orphan))->toBeFalse();
    expect(scientistUser(['lab_id' => null])->can('issue', $orphan))->toBeFalse();
});

test('ADMIN cannot approve or reject a requisition: not in its own branch, not in another, and not even with the right branch on the profile', function () {
    $lab = makeLab();
    $requisition = approverReq($lab);

    foreach ([adminUser(), adminUser(['lab_id' => $lab->id])] as $admin) {
        approverDecide($this, $admin, $requisition)->assertForbidden();
        approverDecide($this, $admin, $requisition, ['decision' => 'REJECT', 'reason' => 'ไม่เห็นควร'])->assertForbidden();
        expect($admin->can('scientistDecide', $requisition))->toBeFalse();
    }

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('SUBMITTED')->and($fresh->scientist_id)->toBeNull();
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->count())->toBe(0);
});

test('a requisition still in DRAFT cannot be decided by anyone, and is not shown to an approver', function () {
    $lab = makeLab();
    $draft = approverReq($lab, 'CHEMICAL', ['status' => 'DRAFT', 'submitted_at' => null]);
    $scientist = scientistUser(['lab_id' => $lab->id]);

    approverDecide($this, $scientist, $draft)->assertForbidden();
    $this->actingAs($scientist)->get(route('requisitions.show', $draft))->assertForbidden();
});

test('a decided requisition cannot be decided again by another scientist (HTTP): 403, one approval row, first approver kept', function () {
    $lab = makeLab();
    $requisition = approverReq($lab);
    $first = scientistUser(['lab_id' => $lab->id]);
    $second = scientistUser(['lab_id' => $lab->id]);

    approverDecide($this, $first, $requisition)->assertRedirect();
    approverDecide($this, $second, $requisition, ['decision' => 'REJECT', 'reason' => 'ช้าไปแล้ว'])->assertForbidden();

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->scientist_id)->toBe($first->id);
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->where('step', 'SCIENTIST')->count())->toBe(1);
});

test('two scientists pressing at once: the second call holds a stale copy (still SUBMITTED) but the service re-reads the row under lock and refuses, first one wins', function () {
    $lab = makeLab();
    $requisition = approverReq($lab);
    $firstView = Requisition::findOrFail($requisition->id);    // both people loaded the page while it was SUBMITTED
    $secondView = Requisition::findOrFail($requisition->id);
    $first = scientistUser(['lab_id' => $lab->id]);
    $second = scientistUser(['lab_id' => $lab->id]);
    $service = app(ApprovalService::class);

    $service->scientistDecide($firstView, $first, 'APPROVE');
    expect($secondView->status)->toBe('SUBMITTED');   // the stale copy really is stale

    expect(fn () => $service->scientistDecide($secondView, $second, 'REJECT', 'กดพร้อมกัน'))
        ->toThrow(InvalidApprovalDecisionException::class);

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED')->and($fresh->scientist_id)->toBe($first->id)->and($fresh->reject_reason)->toBeNull();
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->where('step', 'SCIENTIST')->count())->toBe(1);
    // The loser left no audit row either.
    expect(AuditLog::where('entity_id', $requisition->id)->where('user_id', $second->id)->count())->toBe(0);
});

test('a refused decision (reduced quantity with no reason) rolls back completely: no approval row, no audit row, status untouched', function () {
    $lab = makeLab();
    $requisition = approverReq($lab);
    $line = $requisition->items()->firstOrFail();
    $scientist = scientistUser(['lab_id' => $lab->id]);

    approverDecide($this, $scientist, $requisition, ['decision' => 'APPROVE', 'qty_approved' => [$line->id => '2']])->assertSessionHasErrors('decision');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
    expect(RequisitionApproval::where('requisition_id', $requisition->id)->count())->toBe(0);
    expect(AuditLog::where('entity_id', $requisition->id)->whereIn('action', ['REQ_APPROVE', 'REQ_SELF_APPROVE'])->count())->toBe(0);
    expect($line->fresh()->qty_approved_base)->toBeNull();
});

// --- self approval: allowed, but recorded -----------------------------------------------------------------------

test('a scientist approving their OWN requisition is allowed and leaves an REQ_SELF_APPROVE audit row (requester = approver)', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $own = requisitionWithLines($scientist, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    approverDecide($this, $scientist, $own)->assertRedirect(route('requisitions.show', $own));

    expect($own->fresh()->status)->toBe('APPROVED');
    $rows = AuditLog::where('entity_type', 'requisition')->where('entity_id', $own->id)->where('action', 'REQ_SELF_APPROVE')->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->user_id)->toBe($scientist->id);
    expect($rows[0]->new_value['self_approval'])->toBeTrue();
    expect($rows[0]->new_value['requester_id'])->toBe($scientist->id)->and($rows[0]->new_value['approver_id'])->toBe($scientist->id);
    expect(AuditLog::where('entity_id', $own->id)->where('action', 'REQ_APPROVE')->count())->toBe(0);
});

test('a scientist rejecting their own requisition is recorded as REQ_SELF_REJECT', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $own = requisitionWithLines($scientist, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    approverDecide($this, $scientist, $own, ['decision' => 'REJECT', 'reason' => 'ยื่นผิดรายการ'])->assertRedirect();

    expect($own->fresh()->status)->toBe('REJECTED');
    expect(AuditLog::where('entity_id', $own->id)->where('action', 'REQ_SELF_REJECT')->count())->toBe(1);
});

test('approving somebody else\'s requisition writes a plain REQ_APPROVE audit row, flagged self_approval = false, and no REQ_SELF_* row', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $requisition = approverReq($lab);

    approverDecide($this, $scientist, $requisition)->assertRedirect();

    $rows = AuditLog::where('entity_id', $requisition->id)->where('user_id', $scientist->id)->get();
    expect($rows->pluck('action')->all())->toBe(['REQ_APPROVE']);
    expect($rows[0]->new_value['self_approval'])->toBeFalse();
    expect($rows[0]->new_value['lab_id'])->toBe($lab->id);
});

test('the show page warns the requester-approver that this is their own requisition (and only then)', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $own = requisitionWithLines($scientist, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);
    $others = approverReq($lab);

    $this->actingAs($scientist)->get(route('requisitions.show', $own))->assertOk()->assertSee(__('requisitions.self_approval_notice'));
    $this->actingAs($scientist)->get(route('requisitions.show', $others))->assertOk()->assertSee(__('requisitions.scientist_approve_decision'))->assertDontSee(__('requisitions.self_approval_notice'));
});

test('issuing against one\'s own requisition, or one one approved oneself, leaves an REQ_SELF_ISSUE audit row saying which; a stranger\'s issue leaves none', function () {
    $lab = makeLab();
    $g = Unit::where('code', 'g')->firstOrFail();
    $issue = function (Requisition $requisition, \App\Models\User $issuer) use ($g) {
        $line = $requisition->items()->firstOrFail();
        $container = stockedContainer($line->item_id, '50.000000', $issuer);

        return app(IssueService::class)->issue($line, $container, '1', $g, $issuer, $requisition->requester, hash('sha256', 'k'));
    };

    // (a) issuer = requester
    $self = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $own = requisitionWithLines($self, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'APPROVED']);
    $issue($own, $self);
    $a = AuditLog::where('entity_id', $own->id)->where('action', 'REQ_SELF_ISSUE')->firstOrFail();
    expect($a->new_value['issuer_is_requester'])->toBeTrue()->and($a->new_value['issuer_is_approver'])->toBeFalse();

    // (b) issuer = the approver (scientist_id)
    $approver = scientistUser(['lab_id' => $lab->id]);
    $approved = approverReq($lab, 'CHEMICAL', ['status' => 'APPROVED', 'scientist_id' => $approver->id]);
    $issue($approved, $approver);
    $b = AuditLog::where('entity_id', $approved->id)->where('action', 'REQ_SELF_ISSUE')->firstOrFail();
    expect($b->new_value['issuer_is_approver'])->toBeTrue()->and($b->new_value['issuer_is_requester'])->toBeFalse();

    // (c) a third person issuing: nothing flagged
    $stranger = scientistUser(['lab_id' => $lab->id]);
    $plain = approverReq($lab, 'CHEMICAL', ['status' => 'APPROVED', 'scientist_id' => $approver->id]);
    $issue($plain, $stranger);
    expect(AuditLog::where('entity_id', $plain->id)->where('action', 'REQ_SELF_ISSUE')->count())->toBe(0);
});

// --- who may issue ----------------------------------------------------------------------------------------------

if (! function_exists('issuableReq')) {
    /** An APPROVED single-line requisition of the given category and a stocked container for its line. @return array{Requisition, \App\Models\RequisitionItem, \App\Models\Container, Unit} */
    function issuableReq(\App\Models\Lab $lab, string $category): array
    {
        $requisition = approverReq($lab, $category, ['status' => 'APPROVED']);
        $line = $requisition->items()->firstOrFail();
        $container = stockedContainer($line->item_id, '50.000000', staffUser(), $lab->id);

        return [$requisition, $line, $container, Unit::where('code', 'g')->firstOrFail()];
    }
}

test('a warehouse manager issues requisitions of the kinds they hold in their own branch; the stock moves and the status advances', function () {
    $lab = makeLab();

    foreach (['labManagerUser', 'auditorUser'] as $factory) {
        $manager = $factory(['lab_id' => $lab->id]);   // both kinds
        foreach (['CHEMICAL', 'MATERIAL'] as $category) {
            [$requisition, $line, $container, $g] = issuableReq($lab, $category);

            $this->actingAs($manager)->post(route('requisitions.issue-all', $requisition), ['lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]]])
                ->assertRedirect(route('requisitions.issue.create', $requisition));

            expect($container->fresh()->remaining_qty_base)->toBe('45.000000', "{$factory} {$category}");
            expect($requisition->fresh()->status)->toBe('ISSUED');
            expect(IssueTransaction::where('requisition_item_id', $line->id)->where('issuer_id', $manager->id)->count())->toBe(1);
        }
    }
});

test('a manager cannot issue a kind they do not hold: 403 on the page and on the POST, and no stock moves', function () {
    $lab = makeLab();
    $chemOnly = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]);
    $supplyOnly = labManagerUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]);
    $nothing = auditorUser(['lab_id' => $lab->id, 'itemTypes' => []]);

    $cases = [
        [$chemOnly, 'MATERIAL', false], [$chemOnly, 'CHEMICAL', true],
        [$supplyOnly, 'CHEMICAL', false], [$supplyOnly, 'MATERIAL', true],
        [$nothing, 'CHEMICAL', false], [$nothing, 'MATERIAL', false],
    ];
    foreach ($cases as [$manager, $category, $allowed]) {
        [$requisition, $line, $container, $g] = issuableReq($lab, $category);

        $page = $this->actingAs($manager)->get(route('requisitions.issue.create', $requisition));
        $post = $this->actingAs($manager)->post(route('requisitions.issue-all', $requisition), ['lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]]]);

        if ($allowed) {
            $page->assertOk();
            $post->assertRedirect();
            expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
        } else {
            $page->assertForbidden();
            $post->assertForbidden();
            expect($container->fresh()->remaining_qty_base)->toBe('50.000000', "{$category} must not move");
            expect(IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(0);
        }
    }
});

test('a legacy mixed requisition can only be issued by a manager who holds both kinds', function () {
    $lab = makeLab();
    $mixed = requisitionWithLines(staffUser(['lab_id' => $lab->id]), ['CHEMICAL', 'GLASSWARE'], ['lab_id' => $lab->id, 'status' => 'APPROVED']);

    foreach (['chemical', 'consumable'] as $kind) {
        $single = auditorUser(['lab_id' => $lab->id, 'itemTypes' => [$kind]]);
        $this->actingAs($single)->get(route('requisitions.issue.create', $mixed))->assertForbidden();
    }
    $this->actingAs(auditorUser(['lab_id' => $lab->id]))->get(route('requisitions.issue.create', $mixed))->assertOk();
});

test('a manager of another branch, or with no branch, cannot issue; neither can ADMIN', function () {
    $lab = makeLab();
    [$requisition, $line, $container, $g] = issuableReq($lab, 'CHEMICAL');

    foreach ([auditorUser(['lab_id' => makeLab()->id]), labManagerUser(['lab_id' => null]), adminUser(['lab_id' => $lab->id]), adminUser()] as $user) {
        $this->actingAs($user)->post(route('requisitions.issue-all', $requisition), ['lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]]])->assertForbidden();
    }
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
    expect($requisition->fresh()->status)->toBe('APPROVED');
});

test('a scientist is not narrowed by kind when issuing, but only in their own branch', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);

    foreach (['CHEMICAL', 'MATERIAL'] as $category) {
        [$requisition] = issuableReq($lab, $category);
        $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertOk();
    }
    [$elsewhere] = issuableReq(makeLab(), 'CHEMICAL');
    $this->actingAs($scientist)->get(route('requisitions.issue.create', $elsewhere))->assertForbidden();
});

// --- student requisition: the advisor alone approves ---------------------------------------------------------------

test('a STUDENT requisition becomes APPROVED when the advisor approves, and a scientist of the branch can issue it right away', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $requisition = submittedRequisition($student, ['lab_id' => $lab->id]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($requisition->advisor)->post(route('requisitions.advisor-decide', $requisition), ['decision' => 'APPROVE'])->assertRedirect();

    expect($requisition->fresh()->status)->toBe('APPROVED');
    expect($requisition->fresh()->scientist_id)->toBeNull();   // no second approver was involved

    $line = $requisition->items()->firstOrFail();
    $container = stockedContainer($line->item_id, '50.000000', $student, $lab->id);
    $scientist = scientistUser(['lab_id' => $lab->id]);

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), ['lines' => [$line->id => ['qty' => '5', 'unit_id' => $g->id]]])
        ->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('a manager (of the right kind) can issue an advisor-approved STUDENT requisition too; a scientist of another branch cannot', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $requisition = submittedRequisition($student, ['lab_id' => $lab->id]);
    app(ApprovalService::class)->advisorDecide($requisition, $requisition->advisor, 'APPROVE');

    $this->actingAs(scientistUser(['lab_id' => makeLab()->id]))->get(route('requisitions.issue.create', $requisition))->assertForbidden();
    $this->actingAs(auditorUser(['lab_id' => $lab->id]))->get(route('requisitions.issue.create', $requisition))->assertOk();
});

test('the student path does not open a back door: the advisor\'s REJECT still ends it, and no scientist can approve a student requisition the advisor has not signed', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $waiting = submittedRequisition($student, ['lab_id' => $lab->id]);
    $rejected = submittedRequisition($student, ['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);

    approverDecide($this, $scientist, $waiting)->assertForbidden();
    expect($waiting->fresh()->status)->toBe('SUBMITTED');

    app(ApprovalService::class)->advisorDecide($rejected, $rejected->advisor, 'REJECT', 'ไม่เหมาะสม');
    expect($rejected->fresh()->status)->toBe('REJECTED');
    $this->actingAs($scientist)->get(route('requisitions.issue.create', $rejected))->assertForbidden();
});

// --- notifications --------------------------------------------------------------------------------------------

test('approval pending: every active scientist of the branch is told in-system only; the managers responsible for the kind are told in-system and by email; nobody elsewhere', function () {
    Mail::fake();
    $lab = makeLab();
    $s1 = scientistUser(['lab_id' => $lab->id]);
    $s2 = scientistUser(['lab_id' => $lab->id]);
    $inactive = scientistUser(['lab_id' => $lab->id, 'is_active' => false]);
    $noBranch = scientistUser(['lab_id' => null]);
    $elsewhere = scientistUser(['lab_id' => makeLab()->id]);
    $chemManager = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]);
    $supplyManager = labManagerUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]);
    $requisition = approverReq($lab, 'CHEMICAL');

    app(\App\Domain\Notification\Services\NotificationService::class)->notifyApprovalPending($requisition);

    $told = \App\Models\Notification::where('type', 'requisition.pending_scientist')->pluck('user_id')->sort()->values()->all();
    expect($told)->toBe(collect([$s1, $s2, $chemManager])->map->id->sort()->values()->all());
    foreach ([$inactive, $noBranch, $elsewhere, $supplyManager] as $nobody) {
        expect(\App\Models\Notification::where('user_id', $nobody->id)->count())->toBe(0);
    }

    // Email: the manager only. A scientist never gets one (rotating duty, no flood).
    Mail::assertQueued(NotificationMail::class, 1);
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($chemManager->email));
    Mail::assertNotQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($s1->email) || $mail->hasTo($s2->email));
});

test('approval pending in a branch with only scientists: they are told and ADMIN is NOT sent the no-approver notice', function () {
    Mail::fake();
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $admin = adminUser();

    app(\App\Domain\Notification\Services\NotificationService::class)->notifyApprovalPending(approverReq($lab));

    expect(\App\Models\Notification::where('user_id', $scientist->id)->where('type', 'requisition.pending_scientist')->exists())->toBeTrue();
    expect(\App\Models\Notification::where('user_id', $admin->id)->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('ready to issue (a student requisition the advisor approved): scientists of the branch in-system only, managers of the kind in-system and by email, others nothing', function () {
    Mail::fake();
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $elsewhere = scientistUser(['lab_id' => makeLab()->id]);
    $chemManager = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['chemical']]);
    $supplyManager = labManagerUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]);
    $requisition = approverReq($lab, 'CHEMICAL', ['status' => 'APPROVED']);

    app(\App\Domain\Notification\Services\NotificationService::class)->notifyReadyToIssue($requisition);

    $told = \App\Models\Notification::where('type', 'requisition.ready_to_issue')->pluck('user_id')->sort()->values()->all();
    expect($told)->toBe(collect([$scientist, $chemManager])->map->id->sort()->values()->all());
    expect(\App\Models\Notification::where('user_id', $elsewhere->id)->count())->toBe(0);
    expect(\App\Models\Notification::where('user_id', $supplyManager->id)->count())->toBe(0);

    Mail::assertQueued(NotificationMail::class, 1);
    Mail::assertQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($chemManager->email));
    Mail::assertNotQueued(NotificationMail::class, fn ($mail) => $mail->hasTo($scientist->email));

    $notice = \App\Models\Notification::where('user_id', $scientist->id)->firstOrFail();
    expect($notice->body)->toContain($requisition->doc_no);
});

test('dispensersFor and approversFor follow the policies: the same people who may act are the ones listed', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);
    $manager = auditorUser(['lab_id' => $lab->id, 'itemTypes' => ['consumable']]);
    $admin = adminUser(['lab_id' => $lab->id]);
    $service = app(\App\Domain\Notification\Services\NotificationService::class);

    $submitted = approverReq($lab, 'CHEMICAL');
    $approved = approverReq($lab, 'CHEMICAL', ['status' => 'APPROVED']);

    $listed = fn ($users) => $users->pluck('id')->sort()->values()->all();
    expect($listed($service->approversFor($submitted)))->toBe([$scientist->id]);
    expect($listed($service->dispensersFor($approved)))->toBe([$scientist->id]);
    foreach ([$scientist] as $person) {
        expect($person->can('scientistDecide', $submitted))->toBeTrue();
        expect($person->can('issue', $approved))->toBeTrue();
    }
    foreach ([$manager, $admin] as $person) {
        expect($person->can('scientistDecide', $submitted))->toBeFalse();
        expect($person->can('issue', $approved))->toBeFalse();
    }
});
