<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1 (plan 6.1): which home-page cards each role sees. A card the viewer may
 * not use must be absent from the HTML altogether (not merely hidden), because the server decides
 * in MenuRegistry. Hiding a card is never the protection; see RouteAccessSmokeTest for direct URLs.
 *
 * Expected cards come from the seeded permissions (PermissionSeeder) through the Policies the
 * pages themselves use: requesting = requisition.create; warehouse = any warehouse-side ability
 * (manage requisitions, stock takes, disposals, adjustments, IMS, members, locations, reports,
 * item.manage); back office = user.manage or lab.manage.
 */

dataset('home card matrix', [
    'STUDENT' => ['student', ['chem', 'supply']],
    'STAFF' => ['staff', ['chem', 'supply']],
    'ADVISOR' => ['advisor', ['chem', 'supply']],
    'SCIENTIST (dispenses, so has the warehouse card)' => ['scientist', ['chem', 'supply', 'ops']],
    'LAB_MANAGER' => ['lab_manager', ['chem', 'supply', 'ops']],
    'AUDITOR (same grants as LAB_MANAGER)' => ['auditor', ['chem', 'supply', 'ops']],
    'ADMIN (warehouse card and back office)' => ['admin', ['chem', 'supply', 'ops', 'adm']],
    'STAFF + AUDITOR (several roles)' => ['staff+auditor', ['chem', 'supply', 'ops']],
    'ADVISOR + SCIENTIST (several roles)' => ['advisor+scientist', ['chem', 'supply', 'ops']],
    'STAFF + ADMIN (several roles)' => ['staff+admin', ['chem', 'supply', 'ops', 'adm']],
]);

test('the home page shows exactly the cards the role may use, and the others are not in the HTML', function (string $kind, array $expected) {
    $user = navUser($kind);

    $response = $this->actingAs($user)->get('/')->assertOk()->assertViewIs('home');
    $html = $response->getContent();
    $cards = homeCards($html);

    $titles = fn (array $keys) => array_map(fn (string $k) => __('nav.card_'.$k), $keys);
    $firstUrl = [
        'chem' => route('shop.index', ['type' => 'chemical']),
        'supply' => route('shop.index', ['type' => 'consumable']),
        'ops' => route('warehouse.overview'),
        'adm' => route('admin.users.index'),
    ];

    // Present, in order, each leading to its first tab.
    expect(array_column($cards, 'title'))->toBe($titles($expected));
    expect(array_column($cards, 'href'))->toBe(array_map(fn (string $k) => $firstUrl[$k], $expected));

    // Absent: neither the card's title, its group heading nor the page it leads to is in the HTML.
    $absent = array_values(array_diff(['chem', 'supply', 'ops', 'adm'], $expected));
    foreach ($absent as $key) {
        expect($html)->not->toContain(__('nav.card_'.$key));
        expect($html)->not->toContain($firstUrl[$key]);
    }
    if (! in_array('ops', $expected, true)) {
        expect($html)->not->toContain(__('nav.group_ops'))->not->toContain(route('requisitions.manage'));
    }
    if (! in_array('adm', $expected, true)) {
        expect($html)->not->toContain(__('nav.group_adm'))->not->toContain(route('admin.labs.index'));
    }
})->with('home card matrix');

test('a user holding no ability that opens any card sees the explanation and no card', function () {
    $user = navUser('nocard');

    $response = $this->actingAs($user)->get('/')->assertOk()->assertViewIs('home');

    expect(homeCards($response->getContent()))->toBe([]);
    $response->assertSee(__('home.no_access'));
    $response->assertDontSee(__('nav.card_chem'))->assertDontSee(__('nav.card_ops'))->assertDontSee(__('nav.card_adm'));
});

test('ADMIN sees the warehouse card and the back office, but no approval badge (ADMIN decides no requisition)', function () {
    $admin = navUser('admin');
    $student = studentUser(['lab_id' => $admin->lab_id]);

    // Work that would produce badges for a manager exists in the admin's own branch.
    makeRequisition($student, ['lab_id' => $admin->lab_id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition($student, ['lab_id' => $admin->lab_id, 'status' => 'APPROVED']);

    $cards = collect(homeCards($this->actingAs($admin)->get('/')->assertOk()->getContent()))->keyBy('title');

    expect($cards->keys()->all())->toContain(__('nav.card_ops'))->toContain(__('nav.card_adm'));
    expect($cards[__('nav.card_ops')]['badges'])->toBe([]);   // no waiting-for-approval, no waiting-to-issue
    expect($cards[__('nav.card_adm')]['badges'])->toBe([]);
});

test('only the warehouse card gets a group heading for managers and only the back office one for ADMIN', function () {
    $manager = $this->actingAs(navUser('auditor'))->get('/')->assertOk();
    $manager->assertSee(__('nav.group_ops'))->assertDontSee(__('nav.group_adm'));

    $admin = $this->actingAs(navUser('admin'))->get('/')->assertOk();
    $admin->assertSee(__('nav.group_ops'))->assertSee(__('nav.group_adm'));

    $student = $this->actingAs(navUser('student'))->get('/')->assertOk();
    $student->assertDontSee(__('nav.group_ops'))->assertDontSee(__('nav.group_adm'));
});

test('the cards are plain links (keyboard, screen readers and open-in-new-tab all work), never buttons', function () {
    $html = $this->actingAs(navUser('admin'))->get('/')->assertOk()->getContent();
    $xp = htmlXPath($html);

    expect($xp->query('//main//a[contains(concat(" ", normalize-space(@class), " "), " rounded-xl ")]')->length)->toBe(4);
    expect($xp->query('//main//button')->length)->toBe(0);
    // Every card's icon is decorative.
    foreach ($xp->query('//main//a[contains(concat(" ", normalize-space(@class), " "), " rounded-xl ")]//svg') as $svg) {
        expect($svg->getAttribute('aria-hidden'))->toBe('true');
    }
});
