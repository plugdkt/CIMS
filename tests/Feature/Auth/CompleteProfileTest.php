<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function userWithStudentRole(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'STUDENT')->firstOrFail());

    return $user;
}

test('a student must choose an advisor, other person types do not', function () {
    $lab = makeLab();
    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT',
        'phone' => '0812345678',
        'person_code' => '65123456',
        'lab_id' => $lab->id,
        'program' => 'วิทยาศาสตร์การแพทย์',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
    ]);

    $response->assertSessionHasErrors('advisor_id');
});

test('a user must choose a valid active lab when completing profile', function () {
    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT',
        'phone' => '0812345678',
        'person_code' => '65123456',
        'program' => 'วิทยาศาสตร์การแพทย์',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
    ]);

    $response->assertSessionHasErrors('lab_id');
});

test('completing the profile saves encrypted phone/person_code, lab_id, and stamps profile_completed_at', function () {
    $lab = makeLab();
    $advisorRole = Role::where('code', 'ADVISOR')->firstOrFail();
    $advisor = User::factory()->create();
    $advisor->roles()->attach($advisorRole);

    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT',
        'phone' => '0812345678',
        'person_code' => '65123456',
        'lab_id' => $lab->id,
        'program' => 'วิทยาศาสตร์การแพทย์',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
        'advisor_id' => $advisor->id,
    ]);

    $response->assertRedirect(route('requisitions.create'));

    $user->refresh();
    expect($user->profile_completed_at)->not->toBeNull();
    expect($user->phone_encrypted)->toBe('0812345678');
    expect($user->person_code_encrypted)->toBe('65123456');
    expect($user->advisor_id)->toBe($advisor->id);
    expect($user->lab_id)->toBe($lab->id);
});

test('missing required fields are rejected', function () {
    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), []);

    $response->assertSessionHasErrors(['person_type', 'phone', 'person_code', 'program', 'faculty', 'lab_id']);
});

test('a student who completes profile with lab_id can immediately create requisitions without pending-lab redirect', function () {
    $lab = makeLab();
    $advisorRole = Role::where('code', 'ADVISOR')->firstOrFail();
    $advisor = User::factory()->create();
    $advisor->roles()->attach($advisorRole);

    $user = userWithStudentRole();

    // 1. Visit create requisition when profile not complete -> redirected to complete-profile
    $this->actingAs($user)->get(route('requisitions.create'))
        ->assertRedirect(route('account.complete-profile'));

    // 2. Submit complete-profile with lab_id
    $this->actingAs($user)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT',
        'phone' => '0812345678',
        'person_code' => '65123456',
        'lab_id' => $lab->id,
        'program' => 'วิทยาศาสตร์การแพทย์',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
        'advisor_id' => $advisor->id,
    ])->assertRedirect(route('requisitions.create'));

    // 3. Now visiting create requisition is successful (HTTP 200)
    $this->actingAs($user->fresh())->get(route('requisitions.create'))
        ->assertOk();
});
