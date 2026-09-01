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

test('a non-admin is forbidden from mounting the admin component at all', function () {
    $scientist = userWithRole('SCIENTIST');

    Livewire::actingAs($scientist)
        ->test(UserRoleManager::class)
        ->assertForbidden();
});
