<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** CEO 2026-11-02: ADMIN needs no phone / person code; every other role is unchanged. */
function profilePayload(int $labId, array $overrides = []): array
{
    return array_merge([
        'person_type' => 'STAFF',
        'phone' => '0812345678',
        'person_code' => 'E1234',
        'lab_id' => $labId,
        'program' => 'ทดสอบ',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
    ], $overrides);
}

test('an ADMIN can complete the profile without phone and person code', function () {
    $lab = makeLab();
    $admin = adminUser();

    $payload = profilePayload($lab->id);
    unset($payload['phone'], $payload['person_code']);

    $this->actingAs($admin)->post(route('account.complete-profile.update'), $payload)
        ->assertSessionHasNoErrors();

    $admin->refresh();
    expect($admin->profile_completed_at)->not->toBeNull();
    expect($admin->phone_encrypted)->toBeNull();
    expect($admin->person_code_encrypted)->toBeNull();
});

test('an ADMIN with no phone and person code passes the requisition gates', function () {
    $lab = makeLab();
    $admin = adminUser([
        'person_type' => 'STAFF',
        'program' => null,
        'faculty' => 'วิทยาศาสตร์',
        'lab_id' => $lab->id,
        'profile_completed_at' => now(),
        'phone_encrypted' => null,
        'person_code_encrypted' => null,
    ]);

    $this->actingAs($admin)->get(route('requisitions.create'))->assertOk();
});

test('an ADMIN who does fill a phone still has it validated', function () {
    $lab = makeLab();

    $this->actingAs(adminUser())->post(route('account.complete-profile.update'), profilePayload($lab->id, ['phone' => 'abc']))
        ->assertSessionHasErrors('phone');
});

test('a non-ADMIN is still required to give phone and person code', function (string $role) {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', $role)->firstOrFail());

    $this->actingAs($user)->post(route('account.complete-profile.update'), [])
        ->assertSessionHasErrors(['phone', 'person_code']);
})->with(['STUDENT', 'STAFF', 'ADVISOR', 'LAB_MANAGER']);

test('a non-ADMIN cannot skip phone by claiming ADMIN in the form or omitting fields', function () {
    $lab = makeLab();
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'STAFF')->firstOrFail());

    $payload = profilePayload($lab->id, ['role' => 'ADMIN', 'roles' => ['ADMIN']]);
    unset($payload['phone'], $payload['person_code']);

    $this->actingAs($user)->post(route('account.complete-profile.update'), $payload)
        ->assertSessionHasErrors(['phone', 'person_code']);

    expect($user->fresh()->profile_completed_at)->toBeNull();
});

test('a STUDENT still needs an advisor (BR-02 gate unchanged)', function () {
    $lab = makeLab();
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'STUDENT')->firstOrFail());

    $this->actingAs($user)->post(route('account.complete-profile.update'), profilePayload($lab->id, ['person_type' => 'STUDENT']))
        ->assertSessionHasErrors('advisor_id');
});

test('a non-ADMIN person code of "0" is kept, not treated as blank', function () {
    $lab = makeLab();
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'STAFF')->firstOrFail());

    $this->actingAs($user)->post(route('account.complete-profile.update'), profilePayload($lab->id, ['person_code' => '0']))
        ->assertSessionHasNoErrors();

    expect($user->fresh()->person_code_encrypted)->toBe('0');
});

test('an ADMIN who picks STUDENT still needs an advisor', function () {
    $lab = makeLab();

    $this->actingAs(adminUser())->post(route('account.complete-profile.update'), profilePayload($lab->id, ['person_type' => 'STUDENT']))
        ->assertSessionHasErrors('advisor_id');
});

test('an ADMIN who sends an empty phone is accepted and stored as NULL', function () {
    $lab = makeLab();
    $admin = adminUser();

    $this->actingAs($admin)->post(route('account.complete-profile.update'), profilePayload($lab->id, ['phone' => '', 'person_code' => '']))
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->phone_encrypted)->toBeNull();
});
