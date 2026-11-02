<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-054 (BR-02 hardening) on the complete-profile page:
 *  - the advisor chosen must be an active user holding requisition.approve_advisor, never yourself;
 *  - person type and advisor are locked once a profile has been completed (a student must not turn
 *    themself into STAFF to skip the advisor, or swap advisors); an older account whose profile is
 *    flagged complete but whose value was never filled in is not locked and can still fill it in;
 *  - every change of person type / advisor / branch is written to the audit log, only the keys that
 *    changed, old to new, and nothing is written when nothing changed or the submission is refused.
 */

const PROFILE_AUDIT = 'PROFILE_APPROVAL_FIELDS_CHANGE';
const HELP_PHRASE = 'ผู้ดูแลคลังหรือนักวิทยาศาสตร์ที่ดูแลเรื่องนี้';

if (! function_exists('profileAdvisor')) {
    function profileAdvisor(array $overrides = []): User
    {
        $advisor = User::factory()->create(array_merge(['person_type' => 'LECTURER'], $overrides));
        $advisor->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());

        return $advisor;
    }
}

if (! function_exists('profileUser')) {
    /** A user (STUDENT role) with exactly the profile state given; nothing is implied (the factory randomises person_type). */
    function profileUser(array $state = [], string $role = 'STUDENT'): User
    {
        $user = User::factory()->create(array_merge([
            'person_type' => null, 'advisor_id' => null, 'lab_id' => null, 'profile_completed_at' => null,
        ], $state));
        $user->roles()->attach(Role::where('code', $role)->firstOrFail());

        return $user;
    }
}

if (! function_exists('lockProfilePayload')) {
    function lockProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'STUDENT', 'phone' => '0812345678', 'person_code' => '65123456',
            'lab_id' => test()->profileLab->id, 'program' => 'เคมี', 'faculty' => 'วิทยาศาสตร์',
        ], $overrides);
    }
}

beforeEach(function () {
    $this->profileLab = makeLab();
});

$post = fn ($test, User $user, array $payload) => $test->actingAs($user)->post(route('account.complete-profile.update'), $payload);

// --- the advisor must be a real advisor ----------------------------------------------------------

test('the advisor cannot be yourself, a user without the advisor permission, or a deactivated advisor', function () use ($post) {
    $user = profileUser();
    $user->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());   // holds the permission, still not allowed for themself
    $staff = User::factory()->create(['person_type' => 'STAFF']);
    $staff->roles()->attach(Role::where('code', 'STAFF')->firstOrFail());
    $inactive = profileAdvisor(['is_active' => false]);
    $revoked = profileAdvisor();
    $revoked->roles()->detach(Role::where('code', 'ADVISOR')->firstOrFail());

    foreach ([$user->id, $staff->id, $inactive->id, $revoked->id, 999999] as $bad) {
        $post($this, $user->fresh(), lockProfilePayload(['advisor_id' => $bad]))
            ->assertSessionHasErrors(['advisor_id' => __('auth.validation.advisor_invalid')]);
    }

    expect($user->fresh()->advisor_id)->toBeNull();
    expect($user->fresh()->profile_completed_at)->toBeNull();
    expect(AuditLog::where('action', PROFILE_AUDIT)->count())->toBe(0);
});

test('a valid advisor passes and is stored', function () use ($post) {
    $user = profileUser();
    $advisor = profileAdvisor();

    $post($this, $user, lockProfilePayload(['advisor_id' => $advisor->id]))->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->advisor_id)->toBe($advisor->id);
    expect($user->fresh()->profile_completed_at)->not->toBeNull();
});

test('the form\'s advisor dropdown lists only active advisors, never the viewer', function () {
    $user = profileUser();
    $user->roles()->attach(Role::where('code', 'ADVISOR')->firstOrFail());
    $good = profileAdvisor(['full_name' => 'อาจารย์ ที่ถูกต้อง']);
    $inactive = profileAdvisor(['full_name' => 'อาจารย์ ปิดใช้งาน', 'is_active' => false]);
    $staff = User::factory()->create(['person_type' => 'STAFF', 'full_name' => 'เจ้าหน้าที่ ไม่ใช่อาจารย์']);
    $staff->roles()->attach(Role::where('code', 'STAFF')->firstOrFail());

    $xp = htmlXPath($this->actingAs($user)->get(route('account.complete-profile'))->assertOk()->getContent());
    $values = [];
    foreach ($xp->query('//select[@id="advisor_id"]/option') as $option) {
        $values[] = $option->getAttribute('value');
    }

    expect($values)->toContain((string) $good->id);
    expect($values)->not->toContain((string) $user->id)->not->toContain((string) $inactive->id)->not->toContain((string) $staff->id);
});

// --- person type lock ----------------------------------------------------------------------------

test('first fill: an account that does not hold the STUDENT role (STAFF / ADVISOR / ADMIN) chooses its person type freely', function (string $role, string $chosen) use ($post) {
    $user = profileUser([], $role);

    $post($this, $user, lockProfilePayload(['person_type' => $chosen]))->assertSessionHasNoErrors();

    expect($user->fresh()->person_type)->toBe($chosen);
})->with([
    'STAFF role picks LECTURER' => ['STAFF', 'LECTURER'],
    'STAFF role picks STAFF' => ['STAFF', 'STAFF'],
    'ADVISOR role picks LECTURER' => ['ADVISOR', 'LECTURER'],
    'ADMIN role picks STAFF' => ['ADMIN', 'STAFF'],
]);

// --- A1: an account holding the STUDENT role is always a student -------------------------------------

test('A1: a STUDENT-role account cannot pick STAFF or LECTURER at the first fill (that would skip the advisor step)', function (string $other) use ($post) {
    $advisor = profileAdvisor();
    $user = profileUser();

    $post($this, $user, lockProfilePayload(['person_type' => $other, 'advisor_id' => $advisor->id]))
        ->assertSessionHasErrors(['person_type' => __('auth.validation.person_type_student_only')]);

    expect($user->fresh()->person_type)->toBeNull();
    expect($user->fresh()->profile_completed_at)->toBeNull();
    expect(AuditLog::where('action', PROFILE_AUDIT)->count())->toBe(0);
})->with(['STAFF', 'LECTURER']);

test('A1: a STUDENT-role account sending STUDENT or nothing is stored as STUDENT and must have an advisor', function () use ($post) {
    $advisor = profileAdvisor();

    // Sending nothing: forced to STUDENT, so the advisor is required.
    $noType = profileUser();
    $payload = lockProfilePayload();
    unset($payload['person_type']);
    $post($this, $noType, $payload)->assertSessionHasErrors(['advisor_id' => __('auth.validation.advisor_required_for_student')]);
    expect($noType->fresh()->person_type)->toBeNull();

    $post($this, $noType->fresh(), $payload + ['advisor_id' => $advisor->id])->assertSessionHasNoErrors();
    expect($noType->fresh()->person_type)->toBe('STUDENT');
    expect($noType->fresh()->advisor_id)->toBe($advisor->id);

    // Sending STUDENT explicitly without an advisor: same requirement.
    $explicit = profileUser();
    $post($this, $explicit, lockProfilePayload(['person_type' => 'STUDENT']))->assertSessionHasErrors(['advisor_id' => __('auth.validation.advisor_required_for_student')]);
    $post($this, $explicit->fresh(), lockProfilePayload(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id]))->assertSessionHasNoErrors();
    expect($explicit->fresh()->person_type)->toBe('STUDENT');
});

test('A1: an older STUDENT-role account flagged complete with no person type can still fill in, and is forced to STUDENT', function () use ($post) {
    $advisor = profileAdvisor();
    $user = profileUser(['person_type' => null, 'profile_completed_at' => now()]);

    expect($user->personTypeLocked())->toBeFalse();
    expect($user->personTypeFixed())->toBe('STUDENT');

    $post($this, $user, lockProfilePayload(['person_type' => 'STAFF', 'advisor_id' => $advisor->id]))->assertSessionHasErrors('person_type');
    $post($this, $user->fresh(), lockProfilePayload(['advisor_id' => $advisor->id]))->assertSessionHasNoErrors();

    expect($user->fresh()->person_type)->toBe('STUDENT');
});

test('A1: a STUDENT-role account whose stored type is already STAFF and locked keeps it; sending STUDENT is refused as a lock violation', function () use ($post) {
    $user = profileUser(['person_type' => 'STAFF', 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    expect($user->personTypeFixed())->toBe('STAFF');
    $post($this, $user, lockProfilePayload(['person_type' => 'STUDENT']))
        ->assertSessionHasErrors(['person_type' => __('auth.validation.person_type_locked')]);
    expect($user->fresh()->person_type)->toBe('STAFF');

    // Sending the stored value (or nothing) is fine and keeps it.
    $post($this, $user->fresh(), lockProfilePayload(['person_type' => 'STAFF']))->assertSessionHasNoErrors();
    expect($user->fresh()->person_type)->toBe('STAFF');
});

test('A1 GET: a STUDENT-role account sees person type read-only as student with the student explanation, but still gets the advisor select', function () {
    $user = profileUser();

    $response = $this->actingAs($user)->get(route('account.complete-profile'))->assertOk();
    $xp = htmlXPath($response->getContent());

    expect($xp->query('//select[@id="person_type"]')->length)->toBe(0);
    expect($xp->query('//input[@id="person_type_display"]/@readonly')->length)->toBe(1);
    expect($xp->query('//input[@id="person_type_display"]')->item(0)->getAttribute('value'))->toBe(__('auth.field_person_type_student'));
    expect($xp->query('//input[@type="hidden" and @id="person_type"]')->item(0)->getAttribute('value'))->toBe('STUDENT');
    expect($xp->query('//select[@id="advisor_id"]')->length)->toBe(1);
    $response->assertSee(__('auth.person_type_student_help'));
});

test('after the first fill the person type cannot be changed (STUDENT to STAFF): refused with the lock message, value unchanged', function () use ($post) {
    $advisor = profileAdvisor();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $post($this, $user, lockProfilePayload(['person_type' => 'STAFF', 'advisor_id' => $advisor->id]))
        ->assertSessionHasErrors(['person_type' => __('auth.validation.person_type_locked')]);

    expect($user->fresh()->person_type)->toBe('STUDENT');
    expect(AuditLog::where('action', PROFILE_AUDIT)->count())->toBe(0);
});

test('after the first fill, sending the same person type or none at all is accepted and keeps the stored value', function () use ($post) {
    $advisor = profileAdvisor();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $post($this, $user, lockProfilePayload(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id]))->assertSessionHasNoErrors();
    expect($user->fresh()->person_type)->toBe('STUDENT');

    $withoutType = lockProfilePayload(['advisor_id' => $advisor->id]);
    unset($withoutType['person_type']);
    $post($this, $user->fresh(), $withoutType)->assertSessionHasNoErrors();
    expect($user->fresh()->person_type)->toBe('STUDENT');
});

test('ADMIN is locked too once the profile is complete', function () use ($post) {
    $admin = profileUser(['person_type' => 'STAFF', 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()], 'ADMIN');

    $post($this, $admin, lockProfilePayload(['person_type' => 'LECTURER']))
        ->assertSessionHasErrors(['person_type' => __('auth.validation.person_type_locked')]);

    expect($admin->fresh()->person_type)->toBe('STAFF');
});

test('an older account flagged complete but with no person type is not locked and can still fill it in', function () use ($post) {
    $user = profileUser(['person_type' => null, 'profile_completed_at' => now()], 'STAFF');

    expect($user->personTypeLocked())->toBeFalse();
    $post($this, $user, lockProfilePayload(['person_type' => 'STAFF']))->assertSessionHasNoErrors();

    expect($user->fresh()->person_type)->toBe('STAFF');
    expect($user->fresh()->personTypeLocked())->toBeTrue();
});

// --- advisor lock --------------------------------------------------------------------------------

test('after the first fill the advisor cannot be changed, even to another valid advisor: refused with the lock message, value unchanged', function () use ($post) {
    $first = profileAdvisor();
    $other = profileAdvisor();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $first->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $post($this, $user, lockProfilePayload(['advisor_id' => $other->id]))
        ->assertSessionHasErrors(['advisor_id' => __('auth.validation.advisor_locked')]);

    expect($user->fresh()->advisor_id)->toBe($first->id);
    expect(AuditLog::where('action', PROFILE_AUDIT)->count())->toBe(0);
});

test('after the first fill, sending the same advisor, a blank one or none keeps the stored advisor', function () use ($post) {
    $first = profileAdvisor();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $first->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    foreach ([['advisor_id' => $first->id], ['advisor_id' => ''], []] as $extra) {
        $post($this, $user->fresh(), lockProfilePayload($extra))->assertSessionHasNoErrors();
        expect($user->fresh()->advisor_id)->toBe($first->id);
    }
});

test('an older STUDENT with a completed profile but no advisor is not locked and must/can still choose one by the advisor rules', function () use ($post) {
    $user = profileUser(['person_type' => 'STUDENT', 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);
    expect($user->advisorLocked())->toBeFalse();

    // Required for a student, and subject to the advisor rules.
    $post($this, $user, lockProfilePayload())->assertSessionHasErrors('advisor_id');
    $post($this, $user->fresh(), lockProfilePayload(['advisor_id' => $user->id]))->assertSessionHasErrors(['advisor_id' => __('auth.validation.advisor_invalid')]);

    $advisor = profileAdvisor();
    $post($this, $user->fresh(), lockProfilePayload(['advisor_id' => $advisor->id]))->assertSessionHasNoErrors();
    expect($user->fresh()->advisor_id)->toBe($advisor->id);
});

test('a user whose profile is not completed yet can still change the advisor they already have', function () use ($post) {
    $first = profileAdvisor();
    $second = profileAdvisor();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $first->id, 'profile_completed_at' => null]);

    expect($user->advisorLocked())->toBeFalse();
    $post($this, $user, lockProfilePayload(['advisor_id' => $second->id]))->assertSessionHasNoErrors();

    expect($user->fresh()->advisor_id)->toBe($second->id);
});

// --- the page itself -----------------------------------------------------------------------------

test('GET: a locked user sees read-only fields with the explanation and no selects; an unlocked user sees the selects', function () {
    $advisor = profileAdvisor(['full_name' => 'อาจารย์ที่ปรึกษาทดสอบ']);
    $locked = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $response = $this->actingAs($locked)->get(route('account.complete-profile'))->assertOk();
    $xp = htmlXPath($response->getContent());

    expect($xp->query('//select[@id="person_type"]')->length)->toBe(0);
    expect($xp->query('//select[@id="advisor_id"]')->length)->toBe(0);
    expect($xp->query('//input[@id="person_type_display"]/@readonly')->length)->toBe(1);
    expect($xp->query('//input[@id="advisor_display"]/@readonly')->length)->toBe(1);
    expect($xp->query('//input[@id="advisor_display"]')->item(0)->getAttribute('value'))->toBe('อาจารย์ที่ปรึกษาทดสอบ');
    // The stored values still travel with the form (and the server ignores any tampering with them).
    expect($xp->query('//input[@type="hidden" and @id="person_type"]')->item(0)->getAttribute('value'))->toBe('STUDENT');
    expect($xp->query('//input[@type="hidden" and @id="advisor_id"]')->item(0)->getAttribute('value'))->toBe((string) $advisor->id);
    $response->assertSee(HELP_PHRASE);

    // Not locked and not a STUDENT-role account (STAFF): free choice, both selects.
    $open = profileUser([], 'STAFF');
    $openResponse = $this->actingAs($open)->get(route('account.complete-profile'))->assertOk();
    $openXp = htmlXPath($openResponse->getContent());
    expect($openXp->query('//select[@id="person_type"]')->length)->toBe(1);
    expect($openXp->query('//select[@id="advisor_id"]')->length)->toBe(1);
    $openResponse->assertDontSee(HELP_PHRASE);
});

test('the lock messages tell the user whom to ask', function () {
    expect(__('auth.validation.person_type_locked'))->toContain(HELP_PHRASE);
    expect(__('auth.validation.advisor_locked'))->toContain(HELP_PHRASE);
    expect(__('auth.person_type_locked_help'))->toContain(HELP_PHRASE);
    expect(__('auth.advisor_locked_help'))->toContain(HELP_PHRASE);
});

// --- audit trail ---------------------------------------------------------------------------------

test('audit: the first fill is recorded with only the keys that changed, old to new', function () use ($post) {
    $advisor = profileAdvisor();
    $user = profileUser();

    $post($this, $user, lockProfilePayload(['advisor_id' => $advisor->id]))->assertSessionHasNoErrors();

    $rows = AuditLog::where('action', PROFILE_AUDIT)->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->old_value)->toBe(['person_type' => null, 'advisor_id' => null, 'lab_id' => null]);
    expect($rows[0]->new_value)->toBe(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id]);
    expect($rows[0]->entity_id)->toEqual($user->id);
});

test('audit: a later change records only the key that changed (branch), keeping the locked values out', function () use ($post) {
    $advisor = profileAdvisor();
    $otherLab = makeLab();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $post($this, $user, lockProfilePayload(['advisor_id' => $advisor->id, 'lab_id' => $otherLab->id]))->assertSessionHasNoErrors();

    $row = AuditLog::where('action', PROFILE_AUDIT)->where('user_id', $user->id)->firstOrFail();
    expect($row->old_value)->toBe(['lab_id' => $this->profileLab->id]);
    expect($row->new_value)->toBe(['lab_id' => $otherLab->id]);
});

test('audit: re-saving with nothing changed writes no row, and a refused choice writes none either', function () use ($post) {
    $advisor = profileAdvisor();
    $user = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $post($this, $user, lockProfilePayload(['advisor_id' => $advisor->id]))->assertSessionHasNoErrors();
    expect(AuditLog::where('action', PROFILE_AUDIT)->count())->toBe(0);

    $post($this, $user->fresh(), lockProfilePayload(['person_type' => 'STAFF', 'advisor_id' => $advisor->id]))->assertSessionHasErrors('person_type');
    $fresh = profileUser();
    $post($this, $fresh, lockProfilePayload(['advisor_id' => $fresh->id]))->assertSessionHasErrors('advisor_id');
    expect(AuditLog::where('action', PROFILE_AUDIT)->count())->toBe(0);
});

test('attack: a student cannot become STAFF (to skip the advisor step) or swap advisor by posting new values, hidden fields included', function () use ($post) {
    $advisor = profileAdvisor();
    $evil = profileAdvisor();
    $student = profileUser(['person_type' => 'STUDENT', 'advisor_id' => $advisor->id, 'lab_id' => $this->profileLab->id, 'profile_completed_at' => now()]);

    $post($this, $student, lockProfilePayload(['person_type' => 'STAFF', 'advisor_id' => $evil->id]))->assertSessionHasErrors();
    $post($this, $student->fresh(), lockProfilePayload(['advisor_id' => $evil->id]))->assertSessionHasErrors('advisor_id');
    $post($this, $student->fresh(), lockProfilePayload(['person_type' => 'LECTURER', 'advisor_id' => $advisor->id]))->assertSessionHasErrors('person_type');

    $student->refresh();
    expect($student->person_type)->toBe('STUDENT');
    expect($student->advisor_id)->toBe($advisor->id);
});
