<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FR-RQ-08: a warehouse manager (AUDITOR) can mark a non-student SUBMITTED requisition "เห็นควรให้เบิก"', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBe($auditor->id);
});

test('FR-RQ-08: "ไม่เห็นควรให้เบิก" without a reason is rejected', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'REJECT',
    ])->assertSessionHasErrors('reason');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('FR-RQ-08: "ไม่เห็นควรให้เบิก" with a reason ends the requisition', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'REJECT',
        'reason' => 'ของหมดชั่วคราว',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('REJECTED');
    expect($fresh->reject_reason)->toBe('ของหมดชั่วคราว');
});

test('BR-02: a warehouse manager cannot approve or reject a STUDENT requisition still waiting for the advisor (HTTP layer)', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertForbidden();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'REJECT',
        'reason' => 'ไม่จำเป็น',
    ])->assertForbidden();

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('SUBMITTED');
    expect($fresh->scientist_id)->toBeNull();
});

test('approvals.md K: a legacy ADVISOR_APPROVED STUDENT requisition can still be approved by a warehouse manager via the HTTP layer', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $requisition->update(['status' => 'ADVISOR_APPROVED', 'advisor_signed_at' => now()]);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBe($auditor->id);
});

test('approvals.md K: after the advisor approves, a STUDENT requisition is already APPROVED, so the manager/scientist decision is refused (403)', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    app(\App\Domain\Requisition\Services\ApprovalService::class)->advisorDecide($requisition, $requisition->advisor, 'APPROVE');

    foreach ([$auditor, $scientist] as $actor) {
        $this->actingAs($actor)->post(route('requisitions.scientist-decide', $requisition), ['decision' => 'APPROVE'])->assertForbidden();
    }

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBeNull();
});

test('BR-02: an ADVISOR_APPROVED STUDENT requisition with no advisor_signed_at gets a friendly decision error from the service', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $requisition->update(['status' => 'ADVISOR_APPROVED']); // inconsistent data, advisor_signed_at NULL
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertSessionHasErrors('decision');

    expect($requisition->fresh()->status)->toBe('ADVISOR_APPROVED');
});

test('BR-02: the decision form is hidden on a STUDENT requisition until the advisor has approved', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))
        ->assertOk()
        ->assertDontSee(__('requisitions.scientist_approve_decision'))
        ->assertSee(__('requisitions.status_submitted_student'));

    // Legacy row (advisor signed before 2026-11-04): the decision form appears. A student requisition the
    // advisor approves today is APPROVED at once and never shows this form (covered above).
    $requisition->update(['status' => 'ADVISOR_APPROVED', 'advisor_signed_at' => now()]);

    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))
        ->assertSee(__('requisitions.scientist_approve_decision'));
});

test('BR-02: the manage list tells the warehouse manager a STUDENT requisition is waiting for the advisor, with no decide button', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->get(route('requisitions.manage'))
        ->assertOk()
        ->assertSee(__('requisitions.act_waiting_advisor'))
        ->assertDontSee(__('requisitions.act_decide'));
});

test('a user without requisition.approve_scientist gets 403 on the decision action, even in the requisition\'s own branch', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $dispenserOnly = userWithPermissions(['requisition.issue'], ['lab_id' => $requisition->lab_id]);

    $this->actingAs($dispenserOnly)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertStatus(403);

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('approvals.md K: a SCIENTIST of the requisition\'s own branch can approve it (every scientist of the branch may)', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBe($scientist->id);
});

test('an AUDITOR from a different branch gets 403 on the decision action', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $otherLab = makeLab();
    $otherAuditor = auditorUser(['lab_id' => $otherLab->id]);

    $this->actingAs($otherAuditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertStatus(403);
});

test('the decision form is visible on the show page to own-branch approvers (manager and scientist) only, when eligible', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);
    $otherBranchScientist = scientistUser(['lab_id' => makeLab()->id]);
    $adminSameBranch = adminUser(['lab_id' => $requisition->lab_id]);

    // Own branch AUDITOR and own branch SCIENTIST (approvals.md K) see the decision form
    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))
        ->assertSee(__('requisitions.scientist_approve_decision'));
    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))
        ->assertSee(__('requisitions.scientist_approve_decision'));

    // A scientist of another branch cannot even open it; ADMIN opens it but is never offered the form
    $this->actingAs($otherBranchScientist)->get(route('requisitions.show', $requisition))->assertForbidden();
    $this->actingAs($adminSameBranch)->get(route('requisitions.show', $requisition))
        ->assertOk()
        ->assertDontSee(__('requisitions.scientist_approve_decision'));

    $student = studentUser();
    $draft = makeRequisition($student, ['lab_id' => $requisition->lab_id]);
    $this->actingAs($auditor)->get(route('requisitions.show', $draft))
        ->assertDontSee(__('requisitions.scientist_approve_decision'));
});

test('user-requested 2026-09-21: the requisition review page shows each line item\'s current stock balance', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $line = $requisition->items()->firstOrFail();
    $item = $line->item()->firstOrFail();
    $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
    $ledgerUser = \App\Models\User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        $container->id,
        '42.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $ledgerUser->id),
    );

    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.current_balance'))
        ->assertSee('42 ');
});

test('user-requested 2026-09-23: a warehouse manager can approve less than what was requested, per line', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff); // one line, qty_requested = 5 g
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
        'qty_approved' => [$line->id => '2'],
        'reason' => 'ใช้จริงแค่บางส่วนตามที่เห็นสมควร',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $line->fresh();
    expect($fresh->qty_approved)->toBe('2.000000');
    expect($fresh->qty_approved_base)->toBe('2.000000');
    expect($requisition->fresh()->status)->toBe('APPROVED');
});

test('user-requested 2026-09-23: reducing the approved quantity without a reason is rejected', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
        'qty_approved' => [$line->id => '2'],
    ])->assertSessionHasErrors('decision');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
    expect($line->fresh()->qty_approved_base)->toBeNull();
});

test('user-requested 2026-09-23: approving more than what was requested is rejected', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
        'qty_approved' => [$line->id => '999'],
    ])->assertSessionHasErrors('decision');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('user-requested 2026-09-23: leaving qty_approved unset for a line approves the full requested amount, as before', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    expect($line->fresh()->qty_approved_base)->toBe('5.000000'); // same as qty_requested_base
});

test('user-requested 2026-09-23: issuing up to the reduced approved ceiling completes the requisition, not the original request', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff); // 5 g requested
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
        'qty_approved' => [$line->id => '2'],
        'reason' => 'อนุมัติเท่าที่จำเป็น',
    ]);

    $item = $line->fresh()->item()->firstOrFail();
    $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
    $container = stockedContainer($item->id, '2.000000', $auditor);

    app(\App\Domain\Requisition\Services\IssueService::class)->issue(
        $line->fresh(),
        $container,
        '2.000000',
        $g,
        $auditor,
        $staff,
        hash('sha256', 'sig'),
    );

    // Fully issued against the 2 g approved, not stuck at PARTIALLY_ISSUED waiting for 5 g.
    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('user-requested 2026-09-23: issuing past the reduced approved ceiling still triggers BR-04, relative to that ceiling', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
        'qty_approved' => [$line->id => '2'],
        'reason' => 'อนุมัติเท่าที่จำเป็น',
    ]);

    $item = $line->fresh()->item()->firstOrFail();
    $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
    $container = stockedContainer($item->id, '3.000000', $auditor);

    // Issuing 3 g against a 2 g approved ceiling (50% over) with no remark must fail —
    // it was well within the original 5 g request, but that's no longer the ceiling.
    expect(fn () => app(\App\Domain\Requisition\Services\IssueService::class)->issue(
        $line->fresh(),
        $container,
        '3.000000',
        $g,
        $auditor,
        $staff,
        hash('sha256', 'sig'),
    ))->toThrow(\App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException::class);
});

test('user-requested 2026-09-23: the reduced approved quantity is visible on the show page and the issue page', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $line = $requisition->items()->firstOrFail();

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
        'qty_approved' => [$line->id => '2'],
        'reason' => 'อนุมัติเท่าที่จำเป็น',
    ]);

    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.field_qty_approved'))
        ->assertSee('2 ');

    $dispenser = scientistUser(['lab_id' => $requisition->lab_id]);
    $this->actingAs($dispenser)->get(route('requisitions.issue.create', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.tile_approved'));
});
