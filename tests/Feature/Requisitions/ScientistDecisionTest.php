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
