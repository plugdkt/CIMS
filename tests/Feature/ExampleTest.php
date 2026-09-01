<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest sees the login prompt at /', function () {
    $response = $this->get('/');

    $response->assertStatus(200)->assertSee(__('auth.login'));
});

test('a logged-in user with a role sees the home quick-links page at /', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'ADMIN')->firstOrFail());

    $response = $this->actingAs($user)->get('/');

    $response->assertOk()->assertSee(__('nav.users_roles'));
});
