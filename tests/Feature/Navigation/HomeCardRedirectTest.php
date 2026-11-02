<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1: an account that holds exactly one card skips the home page and lands
 * inside that card. It must never loop (the logo and the page chrome of such an account must not
 * lead back to "/"), and one that holds no card gets an explanation instead of an empty page.
 *
 * No seeded role holds exactly one card (everyone who can request also holds both requisition
 * cards), so these accounts use a throw-away role holding only the permission named.
 */

test('an account with only the warehouse card is redirected into it, and the redirect does not loop', function () {
    // requisition.issue opens the warehouse card (a dispenser) but not the requesting cards.
    $user = userWithPermissions(['requisition.issue'], ['lab_id' => makeLab()->id, 'profile_completed_at' => now()]);

    $this->actingAs($user)->get('/')->assertRedirect(route('warehouse.overview'));

    // Following it lands on a real page (200), not on another redirect.
    $page = $this->actingAs($user)->get(route('warehouse.overview'))->assertOk();
    $nav = layoutNav($page->getContent());

    // No "back to home" button, and the logo leads to the card itself, never to "/" (which would bounce here again).
    expect($nav['back'])->toBeNull();
    expect($nav['logo'])->toBe(route('warehouse.overview'))->not->toBe(route('dashboard'));
    expect($nav['title'])->toBe(__('nav.card_ops'));

    // Same on a page deeper inside the card.
    $deeper = layoutNav($this->actingAs($user)->get(route('requisitions.manage', ['tab' => 'to_issue']))->assertOk()->getContent());
    expect($deeper['back'])->toBeNull();
    expect($deeper['logo'])->toBe(route('warehouse.overview'));
});

test('an account with only the back-office card is redirected into it, and the redirect does not loop', function () {
    $user = userWithPermissions(['user.manage'], ['profile_completed_at' => now()]);

    $this->actingAs($user)->get('/')->assertRedirect(route('admin.users.index'));

    $page = $this->actingAs($user)->get(route('admin.users.index'))->assertOk();
    $nav = layoutNav($page->getContent());

    expect($nav['back'])->toBeNull();
    expect($nav['logo'])->toBe(route('admin.users.index'));
    expect($nav['title'])->toBe(__('nav.card_adm'));
});

test('an account with two or more cards is not redirected: it gets the home page, a back button and a logo that leads home', function () {
    $user = navUser('staff');

    $this->actingAs($user)->get('/')->assertOk()->assertViewIs('home');

    $nav = layoutNav($this->actingAs($user)->get(route('shop.index', ['type' => 'chemical']))->assertOk()->getContent());
    expect($nav['back'])->toBe(route('dashboard'));
    expect($nav['logo'])->toBe(route('dashboard'));
});

test('an account holding no card gets a 200 explanation at /, not a redirect', function () {
    $user = navUser('nocard');

    $response = $this->actingAs($user)->get('/');

    $response->assertOk()->assertViewIs('home')->assertSee(__('home.no_access'));
    expect(homeCards($response->getContent()))->toBe([]);

    // Pages outside any card still work for such an account, with the logo leading home (nothing else to lead to).
    $nav = layoutNav($this->actingAs($user)->get(route('notifications.index'))->assertOk()->getContent());
    expect($nav['logo'])->toBe(route('dashboard'));
    expect($nav['hasSidebar'])->toBeFalse();
});

test('the home page redirect for a guest is not affected: a guest still gets the welcome page', function () {
    $this->get('/')->assertOk()->assertViewIs('welcome');
});
