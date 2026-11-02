<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest sees the login prompt at /', function () {
    $response = $this->get('/');

    $response->assertStatus(200)->assertSee(__('auth.login'));
});

test('a logged-in ADMIN sees the home card page at /, with the back-office card leading to users and roles', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'ADMIN')->firstOrFail());

    // ITM-2610-052: "/" is a set of cards now, not a list of quick links, so the old check on the
    // users-and-roles label moved to where that label lives: the back-office card's first tab.
    $response = $this->actingAs($user)->get('/');

    $response->assertOk()->assertViewIs('home');
    $cards = homeCards($response->getContent());
    expect(array_column($cards, 'title'))->toContain(__('nav.card_adm'));
    $adm = collect($cards)->firstWhere('title', __('nav.card_adm'));
    expect($adm['href'])->toBe(route('admin.users.index'));

    $page = $this->actingAs($user)->get($adm['href'])->assertOk();
    expect(array_column(layoutNav($page->getContent())['tabs'], 'label'))->toContain(__('nav.users_roles'));
    $page->assertSee(__('nav.users_roles'));
});
