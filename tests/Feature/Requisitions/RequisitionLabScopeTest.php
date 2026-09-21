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

    $this->actingAs($manager)->get(route('requisitions.index'))
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

    $this->actingAs($auditor)->get(route('requisitions.index'))
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
