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
    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT',
        'phone' => '0812345678',
        'person_code' => '65123456',
        'program' => 'วิทยาศาสตร์การแพทย์',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
    ]);

    $response->assertSessionHasErrors('advisor_id');
});

test('completing the profile saves encrypted phone/person_code and stamps profile_completed_at', function () {
    $advisorRole = Role::where('code', 'ADVISOR')->firstOrFail();
    $advisor = User::factory()->create();
    $advisor->roles()->attach($advisorRole);

    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), [
        'person_type' => 'STUDENT',
        'phone' => '0812345678',
        'person_code' => '65123456',
        'program' => 'วิทยาศาสตร์การแพทย์',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
        'advisor_id' => $advisor->id,
    ]);

    $response->assertRedirect('/');

    $user->refresh();
    expect($user->profile_completed_at)->not->toBeNull();
    expect($user->phone_encrypted)->toBe('0812345678');
    expect($user->person_code_encrypted)->toBe('65123456');
    expect($user->advisor_id)->toBe($advisor->id);
});

test('missing required fields are rejected', function () {
    $user = userWithStudentRole();

    $response = $this->actingAs($user)->post(route('account.complete-profile.update'), []);

    $response->assertSessionHasErrors(['person_type', 'phone', 'person_code', 'program', 'faculty']);
});
