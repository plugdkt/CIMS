<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * User-reported 2026-09-22: removing requisition.view_all from SCIENTIST on 2026-09-21 left
 * the only role holding requisition.issue unable to reach the requisitions it must dispense —
 * the list hid them, the show page 403'd, and the sole link to the issue page lives on that
 * show page. These tests walk the path a real dispenser takes, not the POST target directly.
 *
 * Dispensing is own-branch work (user-requested the same day), so every case here pins the
 * branch as well as the status.
 */
function dispenserFor(\App\Models\Lab $lab): \App\Models\User
{
    return scientistUser(['lab_id' => $lab->id]);
}

test('a SCIENTIST can open an APPROVED requisition in their own branch, and sees the issue link', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $scientist = dispenserFor($lab);

    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))
        ->assertOk()
        ->assertSee(__('requisitions.go_to_issue'))
        ->assertSee(route('requisitions.issue.create', $requisition), false);
});

test('an APPROVED requisition awaiting issuance appears in the SCIENTIST\'s requisition list', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $scientist = dispenserFor($lab);

    $this->actingAs($scientist)->get(route('requisitions.index'))
        ->assertOk()
        ->assertSee($requisition->doc_no);
});

test('a fully ISSUED requisition stays visible to the SCIENTIST, since returns are still possible (BR-05)', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $requisition->update(['status' => 'ISSUED']);
    $scientist = dispenserFor($lab);

    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))->assertOk();
});

test('the dispensing exception does not leak requisitions still awaiting a decision', function () {
    [$student, $lab] = studentUserWithLab();
    $submitted = submittedRequisition($student, ['doc_no' => 'REQ-2569-90001']);
    $scientist = dispenserFor($lab);

    $this->actingAs($scientist)->get(route('requisitions.show', $submitted))->assertStatus(403);

    $this->actingAs($scientist)->get(route('requisitions.index'))
        ->assertOk()
        ->assertDontSee('REQ-2569-90001');
});

test('branch scoping: a SCIENTIST cannot see or open another branch\'s APPROVED requisition', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $requisition->update(['doc_no' => 'REQ-2569-90002']);
    $otherScientist = dispenserFor(makeLab());

    $this->actingAs($otherScientist)->get(route('requisitions.show', $requisition))->assertStatus(403);

    $this->actingAs($otherScientist)->get(route('requisitions.index'))
        ->assertOk()
        ->assertDontSee('REQ-2569-90002');
});

test('branch scoping: a SCIENTIST gets 403 on the issue page for another branch, not just a hidden link', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $otherScientist = dispenserFor(makeLab());

    $this->actingAs($otherScientist)
        ->get(route('requisitions.issue.create', $requisition))
        ->assertStatus(403);
});

test('a SCIENTIST with no branch assigned can dispense nothing — an account-configuration gap, not a silent pass', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $unassigned = scientistUser(['lab_id' => null]);

    $this->actingAs($unassigned)->get(route('requisitions.show', $requisition))->assertStatus(403);

    expect(app(\App\Domain\Reporting\Services\DashboardService::class)->pendingRequisitionsCount($unassigned))
        ->toBe(0);
});

test('a plain requester still cannot see someone else\'s APPROVED requisition', function () {
    [$student, $lab] = studentUserWithLab();
    $requisition = approvedRequisition($student);
    $otherStaff = staffUser(['lab_id' => $lab->id]);

    $this->actingAs($otherStaff)->get(route('requisitions.show', $requisition))->assertStatus(403);
});

test('the dashboard counts only the SCIENTIST\'s own branch awaiting issuance', function () {
    [$student, $lab] = studentUserWithLab();
    approvedRequisition($student);

    [$otherStudent] = studentUserWithLab();
    approvedRequisition($otherStudent);

    $scientist = dispenserFor($lab);

    expect(app(\App\Domain\Reporting\Services\DashboardService::class)->pendingRequisitionsCount($scientist))
        ->toBe(1);
});
