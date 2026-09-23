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

test('in working stock, a warehouse manager can approve a STUDENT requisition straight from SUBMITTED via the HTTP layer', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($auditor)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBe($auditor->id);
});

test('a SCIENTIST without requisition.approve_scientist gets 403 on the decision action', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    $this->actingAs($scientist)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertStatus(403);
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

test('the warehouse manager decision form is visible on the show page only to own branch manager when eligible', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $auditor = auditorUser(['lab_id' => $requisition->lab_id]);
    $scientist = scientistUser(['lab_id' => $requisition->lab_id]);

    // Own branch AUDITOR sees the decision form
    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))
        ->assertSee(__('requisitions.scientist_approve_decision'));

    // SCIENTIST does NOT see the decision form
    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))
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
        ->assertSee(__('requisitions.field_qty_approved'));
});
