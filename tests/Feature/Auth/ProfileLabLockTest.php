<?php

use App\Livewire\Admin\UserRoleManager;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 (BR-L05): the branch decides who may approve a loan and which assets a person may borrow, so a user
 * must not move themself to another branch from the complete-profile page. User::labLocked() = "has a branch":
 *   - no branch yet: the user chooses one, ONCE (audited, old null -> new);
 *   - a branch already set (first fill-in, an ADMIN, a branch manager or the import): the form can never change it —
 *     the stored value, a blank or nothing are accepted, anything else is refused with the lock message — whatever the
 *     role (ADMIN included) and whether or not the profile was completed. Only an ADMIN changes it, in
 *     UserRoleManager::setLab() (audited as LAB_ASSIGN).
 * The person type and advisor locks (ProfileLockTest) are unchanged.
 *
 * (Written without being run: see the report to the secretary.)
 */

const LAB_LOCK_AUDIT = 'PROFILE_APPROVAL_FIELDS_CHANGE';

if (! function_exists('labLockPayload')) {
    function labLockPayload(mixed $labId, array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'STAFF', 'phone' => '0812345678', 'person_code' => 'E1234',
            'lab_id' => $labId, 'program' => 'ทดสอบ', 'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
        ], $overrides);
    }
}

if (! function_exists('labLockNewUser')) {
    /** A STAFF-role account that has never filled the profile in and has no branch. */
    function labLockNewUser(array $state = []): User
    {
        $user = User::factory()->create(array_merge(['person_type' => null, 'advisor_id' => null, 'lab_id' => null, 'profile_completed_at' => null], $state));
        $user->roles()->attach(Role::where('code', 'STAFF')->firstOrFail());

        return $user;
    }
}

test('User::labLocked() is true exactly when a branch is set', function () {
    expect(User::factory()->create(['lab_id' => null])->labLocked())->toBeFalse();
    expect(User::factory()->create(['lab_id' => makeLab()->id])->labLocked())->toBeTrue();
});

test('a user with no branch chooses one, once: it is stored and audited old null -> new', function () {
    $lab = makeLab();
    $user = labLockNewUser();

    $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($lab->id))->assertSessionHasNoErrors();

    expect($user->fresh()->lab_id)->toBe($lab->id);
    $row = AuditLog::where('action', LAB_LOCK_AUDIT)->where('user_id', $user->id)->firstOrFail();
    expect($row->old_value['lab_id'])->toBeNull()->and($row->new_value['lab_id'])->toBe($lab->id);
});

test('a first fill still needs an existing, active branch', function () {
    $user = labLockNewUser();

    foreach (['', null, '999999', 'abc', makeLab(['is_active' => false])->id] as $bad) {
        $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($bad))->assertSessionHasErrors('lab_id');
    }
    expect($user->fresh()->lab_id)->toBeNull();
});

test('once set, the branch cannot be changed from the form: refused with the lock message, value and audit untouched', function () {
    $labA = makeLab();
    $labB = makeLab();
    $user = staffUser(['lab_id' => $labA->id]);

    foreach ([$labB->id, (string) $labB->id, 999999, 'abc'] as $other) {
        $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($other))
            ->assertSessionHasErrors(['lab_id' => __('auth.validation.lab_locked')]);
    }

    expect($user->fresh()->lab_id)->toBe($labA->id);
    expect(AuditLog::where('action', LAB_LOCK_AUDIT)->count())->toBe(0);
});

test('once set, sending the same branch, a blank or nothing is accepted and keeps the stored branch, with no audit row', function () {
    $lab = makeLab();
    $user = staffUser(['lab_id' => $lab->id]);
    $without = labLockPayload($lab->id);
    unset($without['lab_id']);

    foreach ([labLockPayload($lab->id), labLockPayload((string) $lab->id), labLockPayload(''), $without] as $payload) {
        $this->actingAs($user->fresh())->post(route('account.complete-profile.update'), $payload)->assertSessionHasNoErrors();
        expect($user->fresh()->lab_id)->toBe($lab->id);
    }
    expect(AuditLog::where('action', LAB_LOCK_AUDIT)->count())->toBe(0);
});

test('the lock holds for every role and whether or not the profile was completed (a branch given by an ADMIN or the import counts)', function (string $kind) {
    $labA = makeLab();
    $labB = makeLab();
    $user = match ($kind) {
        'ADMIN, profile completed' => adminUser(['lab_id' => $labA->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]),
        'ADMIN, profile not completed' => adminUser(['lab_id' => $labA->id, 'person_type' => 'STAFF', 'profile_completed_at' => null]),
        'warehouse manager' => auditorUser(['lab_id' => $labA->id, 'person_type' => 'STAFF', 'profile_completed_at' => now()]),
        'scientist, branch set by an ADMIN before the first fill-in' => scientistUser(['lab_id' => $labA->id, 'person_type' => null, 'profile_completed_at' => null]),
    };

    $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($labB->id))->assertSessionHasErrors(['lab_id' => __('auth.validation.lab_locked')]);

    expect($user->fresh()->lab_id)->toBe($labA->id);
})->with([
    'ADMIN, profile completed', 'ADMIN, profile not completed', 'warehouse manager',
    'scientist, branch set by an ADMIN before the first fill-in',
]);

test('a user with a branch can still finish the rest of the profile; the branch stays and only the real changes are audited', function () {
    $lab = makeLab();
    $user = labLockNewUser(['lab_id' => $lab->id]);   // branch given by the import, nothing else filled in yet

    $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($lab->id))->assertSessionHasNoErrors();

    $fresh = $user->fresh();
    expect($fresh->lab_id)->toBe($lab->id)->and($fresh->person_type)->toBe('STAFF')->and($fresh->profile_completed_at)->not->toBeNull();
    $row = AuditLog::where('action', LAB_LOCK_AUDIT)->where('user_id', $user->id)->firstOrFail();
    expect($row->new_value)->toBe(['person_type' => 'STAFF']);   // the branch did not change, so it is not in the row
});

test('the form shows a locked branch read-only (explanation, stored value in a hidden field, no select); an unlocked user gets the select', function () {
    $lab = makeLab();
    $locked = staffUser(['lab_id' => $lab->id]);

    $response = $this->actingAs($locked)->get(route('account.complete-profile'))->assertOk();
    $xp = htmlXPath($response->getContent());
    expect($xp->query('//select[@id="lab_id"]')->length)->toBe(0);
    expect($xp->query('//input[@id="lab_display"]/@readonly')->length)->toBe(1);
    expect($xp->query('//input[@id="lab_display"]')->item(0)->getAttribute('value'))->toContain($lab->code);
    expect($xp->query('//input[@type="hidden" and @id="lab_id"]')->item(0)->getAttribute('value'))->toBe((string) $lab->id);
    $response->assertSee(__('auth.lab_locked_help'));

    $open = labLockNewUser();
    $openResponse = $this->actingAs($open)->get(route('account.complete-profile'))->assertOk();
    $openXp = htmlXPath($openResponse->getContent());
    expect($openXp->query('//select[@id="lab_id"]')->length)->toBe(1);
    expect($openXp->query('//input[@id="lab_display"]')->length)->toBe(0);
    $openResponse->assertDontSee(__('auth.lab_locked_help'));
});

test('the lock message tells the user whom to ask', function () {
    expect(__('auth.validation.lab_locked'))->toContain('ผู้ดูแลระบบ');
    expect(__('auth.lab_locked_help'))->toContain('ผู้ดูแลระบบ');
});

test('attack: tampering with the hidden branch field (or the select a locked user should not have) cannot move a user to another branch', function () {
    $labA = makeLab();
    $labB = makeLab();
    $user = staffUser(['lab_id' => $labA->id]);

    $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($labB->id, ['lab_display' => $labB->code]))->assertSessionHasErrors('lab_id');

    expect($user->fresh()->lab_id)->toBe($labA->id);
});

test('only an ADMIN changes a branch that is set, in the user manager (audited LAB_ASSIGN), and the user is then locked to the new one', function () {
    $labA = makeLab();
    $labB = makeLab();
    $user = staffUser(['lab_id' => $labA->id]);
    $admin = adminUser();

    Livewire::actingAs($admin)->test(UserRoleManager::class)->call('setLab', $user->id, $labB->id);

    expect($user->fresh()->lab_id)->toBe($labB->id);
    $log = AuditLog::where('action', 'LAB_ASSIGN')->where('entity_id', $user->id)->firstOrFail();
    expect($log->old_value['lab_id'])->toBe($labA->id)->and($log->new_value['lab_id'])->toBe($labB->id);

    $this->actingAs($user->fresh())->post(route('account.complete-profile.update'), labLockPayload($labA->id))->assertSessionHasErrors(['lab_id' => __('auth.validation.lab_locked')]);
    $this->actingAs($user->fresh())->post(route('account.complete-profile.update'), labLockPayload($labB->id))->assertSessionHasNoErrors();
    expect($user->fresh()->lab_id)->toBe($labB->id);

    // A non-admin cannot reach the user manager at all.
    Livewire::actingAs(staffUser(['lab_id' => $labA->id]))->test(UserRoleManager::class)->assertForbidden();
});

test('end to end: no branch -> "waiting for a branch" page; after choosing one the loan form opens, and the choice cannot be undone by the user', function () {
    $lab = makeLab();
    $user = labLockNewUser();

    $this->actingAs($user)->get(route('loans.create'))->assertRedirect(route('loans.pending-lab'));

    $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload($lab->id))->assertSessionHasNoErrors();

    $user = $user->fresh();
    $this->actingAs($user)->get(route('loans.pending-lab'))->assertRedirect(route('loans.mine'));
    $this->actingAs($user)->get(route('loans.create'))->assertOk();
    $this->actingAs($user)->post(route('account.complete-profile.update'), labLockPayload(makeLab()->id))->assertSessionHasErrors('lab_id');
    expect($user->fresh()->lab_id)->toBe($lab->id);
});
