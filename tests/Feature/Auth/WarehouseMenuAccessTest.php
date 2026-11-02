<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * User-requested 2026-09-22: the reports page and the stock-take / disposal / adjustment
 * menus belong to the warehouse managers (LAB_MANAGER, AUDITOR) and ADMIN. ADMIN sees
 * every branch and can narrow to one; the managers see only their own branch. ADMIN still
 * holds no ledger write permission (spec §3), so they read these pages but cannot approve.
 */
test('a SCIENTIST no longer reaches reports, stock takes, disposals or adjustments', function () {
    $scientist = scientistUser(['lab_id' => makeLab()->id]);

    $this->actingAs($scientist)->get(route('reports.index'))->assertStatus(403);
    $this->actingAs($scientist)->get(route('stock-takes.index'))->assertStatus(403);
    $this->actingAs($scientist)->get(route('disposals.index'))->assertStatus(403);
    $this->actingAs($scientist)->get(route('adjustments.index'))->assertStatus(403);
});

// ITM-2610-052: the sidebar is gone. The four pages are now tabs of the warehouse card
// (งานคลัง), so the "menu" checks look at the home page and at that card's tab bar.
test('none of those four menus is offered to a SCIENTIST (home page or warehouse tab bar)', function () {
    $scientist = navUser('scientist');
    $labels = [__('nav.reports'), __('nav.stock_takes'), __('nav.disposals'), __('nav.adjustments')];
    $urls = [route('reports.index'), route('stock-takes.index'), route('disposals.index'), route('adjustments.index')];

    // Home page: the SCIENTIST has a warehouse card (for dispensing) but nothing leads to the four pages.
    $home = $this->actingAs($scientist)->get(route('dashboard'))->assertOk();
    foreach ($labels as $label) {
        $home->assertDontSee($label);
    }
    foreach ($urls as $url) {
        $home->assertDontSee($url, false);
    }

    // Inside the warehouse card: neither the tab bar nor the page itself links to them.
    foreach ([route('warehouse.overview'), route('requisitions.manage', ['tab' => 'to_issue'])] as $page) {
        $response = $this->actingAs($scientist)->get($page)->assertOk();
        $tabLabels = array_column(layoutNav($response->getContent())['tabs'], 'label');
        foreach ($labels as $label) {
            expect($tabLabels)->not->toContain($label);
        }
        foreach ($urls as $url) {
            $response->assertDontSee($url, false);
        }
    }
});

test('a warehouse manager reaches all four, and finds them as tabs of the warehouse card', function () {
    $manager = navUser('auditor');

    $this->actingAs($manager)->get(route('reports.index'))->assertOk();
    $this->actingAs($manager)->get(route('stock-takes.index'))->assertOk();
    $this->actingAs($manager)->get(route('disposals.index'))->assertOk();
    $this->actingAs($manager)->get(route('adjustments.index'))->assertOk();

    // Home: the warehouse card exists (the four pages are its tabs, no longer home-page links).
    $cards = homeCards($this->actingAs($manager)->get(route('dashboard'))->assertOk()->getContent());
    expect(array_column($cards, 'title'))->toContain(__('nav.card_ops'));

    // The warehouse card's tab bar links all four.
    $tabs = layoutNav($this->actingAs($manager)->get(route('warehouse.overview'))->assertOk()->getContent())['tabs'];
    $byLabel = array_column($tabs, 'href', 'label');
    expect($byLabel)->toHaveKey(__('nav.reports'), route('reports.index'))
        ->toHaveKey(__('nav.stock_takes'), route('stock-takes.index'))
        ->toHaveKey(__('nav.disposals'), route('disposals.index'))
        ->toHaveKey(__('nav.adjustments'), route('adjustments.index'));
});

test('an ADMIN reaches all four too', function () {
    $admin = adminUser();

    $this->actingAs($admin)->get(route('reports.index'))->assertOk();
    $this->actingAs($admin)->get(route('stock-takes.index'))->assertOk();
    $this->actingAs($admin)->get(route('disposals.index'))->assertOk();
    $this->actingAs($admin)->get(route('adjustments.index'))->assertOk();
});

test('spec §3: an ADMIN reads the adjustment history but gets no "new adjustment" form', function () {
    $admin = adminUser();

    $this->actingAs($admin)->get(route('adjustments.index'))
        ->assertOk()
        // The label also appears in the page title and the tab bar, so assert on the link.
        ->assertDontSee(route('adjustments.create'), false);

    $this->actingAs($admin)->get(route('adjustments.create'))->assertStatus(403);
});

test('reports are branch-scoped: a manager sees their own branch only, with no lab picker to widen it', function () {
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id]);

    $this->actingAs($manager)->get(route('reports.index'))
        ->assertOk()
        ->assertSee(__('reports.restricted_to_own_lab'))
        ->assertDontSee(__('reports.all_labs'));
});

test('reports for an ADMIN span every branch and offer a lab picker', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $admin = adminUser();

    $this->actingAs($admin)->get(route('reports.index'))
        ->assertOk()
        ->assertSee(__('reports.all_labs'))
        ->assertSee($lab->name_th)
        ->assertSee($otherLab->name_th);
});
