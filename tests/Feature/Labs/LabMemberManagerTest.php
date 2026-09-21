<?php

use App\Livewire\Labs\LabMemberManager;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a non-LAB_MANAGER is forbidden from mounting the member-management component', function () {
    $scientist = scientistUser();

    Livewire::actingAs($scientist)
        ->test(LabMemberManager::class)
        ->assertForbidden();
});

test('a LAB_MANAGER can whitelist an unassigned STUDENT into their own branch, and it is audited', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => null]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->call('assign', $student->id);

    expect($student->fresh()->lab_id)->toBe($lab->id);
    $log = AuditLog::where('action', 'LAB_MEMBER_ASSIGN')->where('entity_id', $student->id)->first();
    expect($log)->not->toBeNull();
});

test('a LAB_MANAGER can remove a member of their own branch', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => $lab->id]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->call('remove', $student->id);

    expect($student->fresh()->lab_id)->toBeNull();
    $log = AuditLog::where('action', 'LAB_MEMBER_REMOVE')->where('entity_id', $student->id)->first();
    expect($log)->not->toBeNull();
});

test('a LAB_MANAGER cannot poach a member already assigned to a different branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => $otherLab->id]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->call('assign', $student->id);

    expect($student->fresh()->lab_id)->toBe($otherLab->id);
});

test('a LAB_MANAGER cannot remove a member of a different branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $student = studentUser(['lab_id' => $otherLab->id]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->call('remove', $student->id);

    expect($student->fresh()->lab_id)->toBe($otherLab->id);
});

test('an unassigned SCIENTIST is visible and can be whitelisted into the branch, and it is audited', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => null]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->assertSee($scientist->full_name)
        ->call('assign', $scientist->id);

    expect($scientist->fresh()->lab_id)->toBe($lab->id);
    $log = AuditLog::where('action', 'LAB_MEMBER_ASSIGN')->where('entity_id', $scientist->id)->first();
    expect($log)->not->toBeNull();
});

test('a LAB_MANAGER can remove a SCIENTIST from their own branch', function () {
    $lab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $lab->id]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->call('remove', $scientist->id);

    expect($scientist->fresh()->lab_id)->toBeNull();
});

test('a LAB_MANAGER cannot poach a SCIENTIST already assigned to a different branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $manager = labManagerUser(['lab_id' => $lab->id]);
    $scientist = scientistUser(['lab_id' => $otherLab->id]);

    Livewire::actingAs($manager)
        ->test(LabMemberManager::class)
        ->call('assign', $scientist->id);

    expect($scientist->fresh()->lab_id)->toBe($otherLab->id);
});
