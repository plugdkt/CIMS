<?php

use App\Domain\Auth\Services\PseudonymizeUserService;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SEC-PD-04: pseudonymize overwrites every PII field and marks the row inactive', function () {
    $user = User::factory()->create([
        'full_name' => 'สมชาย ใจดี',
        'phone_encrypted' => '0812345678',
        'person_code_encrypted' => '6512345',
        'program' => 'เคมี',
        'faculty' => 'วิทยาศาสตร์การแพทย์',
    ]);
    $originalId = $user->id;
    $originalSsoSubject = $user->sso_subject;

    app(PseudonymizeUserService::class)->pseudonymize($user);
    $user->refresh();

    expect($user->id)->toBe($originalId);
    expect($user->sso_subject)->toBe($originalSsoSubject); // left untouched, deliberately
    expect($user->full_name)->toContain((string) $originalId);
    expect($user->full_name)->not->toBe('สมชาย ใจดี');
    expect($user->phone_encrypted)->toBeNull();
    expect($user->person_code_encrypted)->toBeNull();
    expect($user->program)->toBeNull();
    expect($user->faculty)->toBeNull();
    expect($user->is_active)->toBeFalse();
    expect($user->pseudonymized_at)->not->toBeNull();
});

test('SEC-PD-04: a requisition still references the pseudonymized user by id, undisturbed', function () {
    $staff = staffUser();
    $requisition = makeRequisition($staff);

    app(\App\Domain\Auth\Services\PseudonymizeUserService::class)->pseudonymize($staff);

    expect(Requisition::find($requisition->id)->requester_id)->toBe($staff->id);
});
