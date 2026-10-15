<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('branch scoping: a LAB_MANAGER can view a requisition in their own branch but gets 403 for another branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $requisition = makeRequisition($student, ['lab_id' => $lab->id]);

    $manager = labManagerUser(['lab_id' => $lab->id]);
    $this->actingAs($manager)->get(route('requisitions.show', $requisition))->assertOk();

    $otherManager = labManagerUser(['lab_id' => $otherLab->id]);
    $this->actingAs($otherManager)->get(route('requisitions.show', $requisition))->assertStatus(403);
});

test('branch scoping: the requisition list only shows a LAB_MANAGER their own branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $otherStudent = studentUser(['lab_id' => $otherLab->id]);
    $ownRequisition = makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-00001']);
    makeRequisition($otherStudent, ['lab_id' => $otherLab->id, 'doc_no' => 'REQ-2569-00002']);

    $manager = labManagerUser(['lab_id' => $lab->id]);

    $this->actingAs($manager)->get(route('requisitions.manage'))
        ->assertOk()
        ->assertSee($ownRequisition->doc_no)
        ->assertDontSee('REQ-2569-00002');
});

test('branch scoping: an AUDITOR (ผู้ดูแลคลัง) can view a requisition in their own branch but gets 403 for another branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $requisition = makeRequisition($student, ['lab_id' => $lab->id]);

    $auditor = auditorUser(['lab_id' => $lab->id]);
    $this->actingAs($auditor)->get(route('requisitions.show', $requisition))->assertOk();

    $otherAuditor = auditorUser(['lab_id' => $otherLab->id]);
    $this->actingAs($otherAuditor)->get(route('requisitions.show', $requisition))->assertStatus(403);
});

test('branch scoping: the requisition list only shows an AUDITOR their own branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $otherStudent = studentUser(['lab_id' => $otherLab->id]);
    $ownRequisition = makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-00001']);
    makeRequisition($otherStudent, ['lab_id' => $otherLab->id, 'doc_no' => 'REQ-2569-00002']);

    $auditor = auditorUser(['lab_id' => $lab->id]);

    $this->actingAs($auditor)->get(route('requisitions.manage'))
        ->assertOk()
        ->assertSee($ownRequisition->doc_no)
        ->assertDontSee('REQ-2569-00002');
});

test('requisition list shows a SCIENTIST only their own requisitions, even within the same branch', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);

    $studentReq = makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-00010']);
    $scientistReq = makeRequisition($scientist, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-00011']);

    $this->actingAs($scientist)->get(route('requisitions.index'))
        ->assertOk()
        ->assertSee($scientistReq->doc_no)
        ->assertDontSee($studentReq->doc_no);
});

test('SCIENTIST holding requisition.view_own cannot view another user\'s requisition', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $requisition = makeRequisition($student, ['lab_id' => $lab->id]);

    $scientist = scientistUser(['lab_id' => $lab->id]);
    $this->actingAs($scientist)->get(route('requisitions.show', $requisition))->assertStatus(403);
});

test('the requester list shows only what the user filed, even for a warehouse manager, who gets a separate manage page', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $manager = labManagerUser(['lab_id' => $lab->id, 'profile_completed_at' => now()]);
    $theirs = makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-70001']);
    $mine = makeRequisition($manager, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-70002']);

    // "ใบเบิกของฉัน": a manager sees only their own filings here...
    $this->actingAs($manager)->get(route('requisitions.index'))->assertOk()
        ->assertSee($mine->doc_no)->assertDontSee($theirs->doc_no);
    // ...and everything in the branch on the manage page, which a plain requester cannot open.
    $this->actingAs($manager)->get(route('requisitions.manage'))->assertOk()
        ->assertSee($mine->doc_no)->assertSee($theirs->doc_no);
    $this->actingAs($student)->get(route('requisitions.manage'))->assertForbidden();
    $this->actingAs($student)->get(route('requisitions.index'))->assertOk()->assertSee($theirs->doc_no)->assertDontSee($mine->doc_no);
});

test('the manage page filters by status tab and counts each tab', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $manager = labManagerUser(['lab_id' => $lab->id]);
    makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-71001', 'status' => 'SUBMITTED']);
    makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-71002', 'status' => 'APPROVED']);
    makeRequisition($student, ['lab_id' => $lab->id, 'doc_no' => 'REQ-2569-71003', 'status' => 'ISSUED']);

    \Livewire\Livewire::actingAs($manager)->test(\App\Livewire\Requisitions\RequisitionManageTable::class)
        ->assertSee('REQ-2569-71001')->assertSee('REQ-2569-71002')->assertSee('REQ-2569-71003')
        ->call('setTab', 'to_issue')->assertSee('REQ-2569-71002')->assertDontSee('REQ-2569-71001')->assertDontSee('REQ-2569-71003')
        ->call('setTab', 'waiting')->assertSee('REQ-2569-71001')->assertDontSee('REQ-2569-71002')
        ->call('setTab', 'nonsense')->assertSee('REQ-2569-71003');
});
