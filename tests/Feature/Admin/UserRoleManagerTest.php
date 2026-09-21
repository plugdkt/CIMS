<?php

use App\Livewire\Admin\UserRoleManager;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithRole(string $roleCode): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', $roleCode)->firstOrFail());

    return $user;
}

test('a user without user.manage gets HTTP 403 on the admin users page', function () {
    $scientist = userWithRole('SCIENTIST');

    $this->actingAs($scientist)->get(route('admin.users.index'))->assertStatus(403);
});

test('ADMIN can see the admin users page and list of users', function () {
    $admin = userWithRole('ADMIN');
    $other = userWithRole('STUDENT');

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee($other->full_name);
});

test('ADMIN can assign a role to a user and it is audited', function () {
    $admin = userWithRole('ADMIN');
    $target = User::factory()->create();
    $role = Role::where('code', 'SCIENTIST')->firstOrFail();

    Livewire::actingAs($admin)
        ->test(UserRoleManager::class)
        ->call('toggleRole', $target->id, $role->id);

    expect($target->fresh()->roles->pluck('code')->all())->toBe(['SCIENTIST']);

    $log = AuditLog::where('action', 'ROLE_CHANGE')->where('entity_id', $target->id)->first();
    expect($log)->not->toBeNull();
    expect($log->new_value['roles'])->toBe(['SCIENTIST']);
});

test('ADMIN can remove a role by toggling it again', function () {
    $admin = userWithRole('ADMIN');
    $target = userWithRole('SCIENTIST');
    $role = Role::where('code', 'SCIENTIST')->firstOrFail();

    Livewire::actingAs($admin)
        ->test(UserRoleManager::class)
        ->call('toggleRole', $target->id, $role->id);

    expect($target->fresh()->roles)->toBeEmpty();
});

test('ADMIN can deactivate a user account (CMIS-side only)', function () {
    $admin = userWithRole('ADMIN');
    $target = User::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(UserRoleManager::class)
        ->call('toggleActive', $target->id);

    expect($target->fresh()->is_active)->toBeFalse();

    $log = AuditLog::where('action', 'USER_ACTIVE_TOGGLE')->where('entity_id', $target->id)->first();
    expect($log)->not->toBeNull();
});

test('ADMIN can assign and clear a user\'s branch (lab_id) and it is audited', function () {
    $admin = userWithRole('ADMIN');
    $target = User::factory()->create();
    $lab = makeLab();

    Livewire::actingAs($admin)
        ->test(UserRoleManager::class)
        ->call('setLab', $target->id, $lab->id);

    expect($target->fresh()->lab_id)->toBe($lab->id);
    $log = AuditLog::where('action', 'LAB_ASSIGN')->where('entity_id', $target->id)->first();
    expect($log)->not->toBeNull();
    expect($log->new_value['lab_id'])->toBe($lab->id);

    Livewire::actingAs($admin)
        ->test(UserRoleManager::class)
        ->call('setLab', $target->id, null);

    expect($target->fresh()->lab_id)->toBeNull();
});

test('a non-admin is forbidden from mounting the admin component at all', function () {
    $scientist = userWithRole('SCIENTIST');

    Livewire::actingAs($scientist)
        ->test(UserRoleManager::class)
        ->assertForbidden();
});

test('personnel are split into one tab per branch, and a lab tab never shows a student', function () {
    $admin = userWithRole('ADMIN');
    $labA = makeLab(['name_th' => 'สาขา A']);
    $labB = makeLab(['name_th' => 'สาขา B']);
    $staffA = userWithRole('STAFF');
    $staffA->update(['lab_id' => $labA->id]);
    $staffB = userWithRole('STAFF');
    $staffB->update(['lab_id' => $labB->id]);
    $studentInLabA = userWithRole('STUDENT');
    $studentInLabA->update(['lab_id' => $labA->id]);

    $component = Livewire::actingAs($admin)->test(UserRoleManager::class);

    // Defaults to the first lab tab (alphabetical), never the flat everyone-mixed list.
    $component->assertSee($staffA->full_name)->assertDontSee($staffB->full_name)->assertDontSee($studentInLabA->full_name);

    $component->call('selectTab', (string) $labB->id)
        ->assertSee($staffB->full_name)->assertDontSee($staffA->full_name);
});

test('the student tab shows every student regardless of branch, and nothing else', function () {
    $admin = userWithRole('ADMIN');
    $lab = makeLab();
    $studentWithLab = userWithRole('STUDENT');
    $studentWithLab->update(['lab_id' => $lab->id]);
    $studentNoLab = userWithRole('STUDENT');
    $staffInSameLab = userWithRole('STAFF');
    $staffInSameLab->update(['lab_id' => $lab->id]);

    Livewire::actingAs($admin)->test(UserRoleManager::class)
        ->call('selectTab', 'students')
        ->assertSee($studentWithLab->full_name)
        ->assertSee($studentNoLab->full_name)
        ->assertDontSee($staffInSameLab->full_name);
});

test('the unassigned tab catches personnel with no branch, e.g. ADMIN/AUDITOR', function () {
    $admin = userWithRole('ADMIN');
    $auditorNoLab = userWithRole('AUDITOR');
    $lab = makeLab();
    $staffWithLab = userWithRole('STAFF');
    $staffWithLab->update(['lab_id' => $lab->id]);

    Livewire::actingAs($admin)->test(UserRoleManager::class)
        ->call('selectTab', 'unassigned')
        ->assertSee($auditorNoLab->full_name)
        ->assertDontSee($staffWithLab->full_name);
});

test('the active lab tab is visually highlighted, not just the students/unassigned tabs', function () {
    // Regression guard: array keys built from (string) $lab->id get silently coerced
    // back to int by PHP's own array-key rules, so a strict $tab === $key comparison
    // in the view would never match a lab tab (only the string-literal 'students'/
    // 'unassigned' keys) — the view must compare against (string) $key instead.
    $admin = userWithRole('ADMIN');
    $lab = makeLab();

    $html = Livewire::actingAs($admin)->test(UserRoleManager::class)->html();

    preg_match('/<button[^>]*selectTab\(\''.$lab->id.'\'\)[^>]*>/', $html, $labButton);
    preg_match('/<button[^>]*selectTab\(\'unassigned\'\)[^>]*>/', $html, $unassignedButton);

    expect($labButton)->not->toBeEmpty();
    expect($labButton[0])->toContain('bg-accent text-white');
    expect($unassignedButton[0])->not->toContain('bg-accent text-white');
});

test('the tab bar shows a live count of users per tab', function () {
    $admin = userWithRole('ADMIN');
    $lab = makeLab(['name_th' => 'สาขานับจำนวน']);
    $staff = userWithRole('STAFF');
    $staff->update(['lab_id' => $lab->id]);
    userWithRole('STUDENT');
    userWithRole('STUDENT');

    $component = Livewire::actingAs($admin)->test(UserRoleManager::class);
    $tabs = $component->instance()->tabs;

    expect($tabs[(string) $lab->id]['count'])->toBe(1);
    expect($tabs['students']['count'])->toBe(2);
});
