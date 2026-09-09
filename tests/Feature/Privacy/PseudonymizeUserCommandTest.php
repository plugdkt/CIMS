<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SEC-PD-04: the command pseudonymizes a user by username after confirmation, and audit-logs it', function () {
    $user = User::factory()->create(['username' => 'somchai.j']);

    $this->artisan('users:pseudonymize', ['user' => 'somchai.j'])
        ->expectsConfirmation('ยืนยันล้างข้อมูลส่วนบุคคลของ "'.$user->full_name.'" (somchai.j) อย่างถาวร? การกระทำนี้ย้อนกลับไม่ได้', 'yes')
        ->assertExitCode(0);

    expect($user->fresh()->pseudonymized_at)->not->toBeNull();
    expect(AuditLog::where('action', 'PDPA_PSEUDONYMIZE')->where('entity_id', $user->id)->exists())->toBeTrue();
});

test('SEC-PD-04: declining the confirmation leaves the user untouched', function () {
    $user = User::factory()->create(['username' => 'declineme']);

    $this->artisan('users:pseudonymize', ['user' => 'declineme'])
        ->expectsConfirmation('ยืนยันล้างข้อมูลส่วนบุคคลของ "'.$user->full_name.'" (declineme) อย่างถาวร? การกระทำนี้ย้อนกลับไม่ได้', 'no')
        ->assertExitCode(0);

    expect($user->fresh()->pseudonymized_at)->toBeNull();
});

test('SEC-PD-04: an unknown identifier fails cleanly', function () {
    $this->artisan('users:pseudonymize', ['user' => 'no-such-user'])->assertExitCode(1);
});

test('SEC-PD-04: an already-pseudonymized user cannot be pseudonymized twice', function () {
    $user = User::factory()->create(['username' => 'twice', 'pseudonymized_at' => now()]);

    $this->artisan('users:pseudonymize', ['user' => 'twice'])->assertExitCode(1);
});

test('SEC-PD-04: the user can also be found by ulid', function () {
    $user = User::factory()->create();

    $this->artisan('users:pseudonymize', ['user' => $user->ulid])
        ->expectsConfirmation('ยืนยันล้างข้อมูลส่วนบุคคลของ "'.$user->full_name.'" ('.$user->username.') อย่างถาวร? การกระทำนี้ย้อนกลับไม่ได้', 'yes')
        ->assertExitCode(0);

    expect($user->fresh()->pseudonymized_at)->not->toBeNull();
});
