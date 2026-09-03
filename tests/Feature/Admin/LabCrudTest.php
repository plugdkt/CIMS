<?php

use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adminUser(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'ADMIN')->firstOrFail());

    return $user;
}

test('a non-admin gets 403 on the labs index', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)->get(route('admin.labs.index'))->assertStatus(403);
});

test('ADMIN can create a lab', function () {
    $admin = adminUser();

    $response = $this->actingAs($admin)->post(route('admin.labs.store'), [
        'code' => 'LAB-CHEM-1',
        'name_th' => 'ห้องปฏิบัติการเคมี 1',
        'faculty' => 'คณะวิทยาศาสตร์การแพทย์',
        'is_active' => '1',
    ]);

    $lab = Lab::where('code', 'LAB-CHEM-1')->first();
    expect($lab)->not->toBeNull();
    $response->assertRedirect(route('admin.labs.edit', $lab));
});

test('lab code must be unique', function () {
    $admin = adminUser();
    makeLab(['code' => 'LAB-DUP']);

    $this->actingAs($admin)->post(route('admin.labs.store'), [
        'code' => 'LAB-DUP',
        'name_th' => 'ซ้ำ',
    ])->assertSessionHasErrors('code');
});

test('ADMIN can update a lab', function () {
    $admin = adminUser();
    $lab = makeLab(['name_th' => 'เดิม']);

    $this->actingAs($admin)->put(route('admin.labs.update', $lab), [
        'code' => $lab->code,
        'name_th' => 'ชื่อใหม่',
        'is_active' => '1',
    ])->assertRedirect(route('admin.labs.edit', $lab));

    expect($lab->fresh()->name_th)->toBe('ชื่อใหม่');
});
