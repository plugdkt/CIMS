<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FR-RQ-08: a scientist can mark a non-student SUBMITTED requisition "เห็นควรให้เบิก"', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    $this->actingAs($scientist)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('APPROVED');
    expect($fresh->scientist_id)->toBe($scientist->id);
});

test('FR-RQ-08: "ไม่เห็นควรให้เบิก" without a reason is rejected', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    $this->actingAs($scientist)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'REJECT',
    ])->assertSessionHasErrors('reason');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('FR-RQ-08: "ไม่เห็นควรให้เบิก" with a reason ends the requisition', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    $this->actingAs($scientist)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'REJECT',
        'reason' => 'ของหมดชั่วคราว',
    ])->assertRedirect(route('requisitions.show', $requisition));

    $fresh = $requisition->fresh();
    expect($fresh->status)->toBe('REJECTED');
    expect($fresh->reject_reason)->toBe('ของหมดชั่วคราว');
});

test('BR-02: a scientist gets a friendly error trying to approve a STUDENT requisition with no advisor sign-off', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $scientist = scientistUser();

    $this->actingAs($scientist)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertSessionHasErrors('decision');

    expect($requisition->fresh()->status)->toBe('SUBMITTED');
});

test('a user without requisition.approve_scientist gets 403', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $labManager = labManagerUser();

    $this->actingAs($labManager)->post(route('requisitions.scientist-decide', $requisition), [
        'decision' => 'APPROVE',
    ])->assertStatus(403);
});

test('the scientist decision form is visible on the show page only when eligible', function () {
    $staff = staffUser();
    $requisition = submittedRequisition($staff);
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))
        ->assertSee(__('requisitions.scientist_approve_decision'));

    $student = studentUser();
    $draft = makeRequisition($student);
    $this->actingAs($scientist)->get(route('requisitions.show', $draft))
        ->assertDontSee(__('requisitions.scientist_approve_decision'));
});
