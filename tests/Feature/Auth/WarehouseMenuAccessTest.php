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

test('none of those four menus render in a SCIENTIST\'s sidebar', function () {
    $scientist = scientistUser(['lab_id' => makeLab()->id]);

    $this->actingAs($scientist)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(__('nav.reports'))
        ->assertDontSee(__('nav.stock_takes'))
        ->assertDontSee(__('nav.disposals'))
        ->assertDontSee(__('nav.adjustments'));
});

test('a warehouse manager reaches all four, and sees them in the sidebar', function () {
    $manager = auditorUser(['lab_id' => makeLab()->id]);

    $this->actingAs($manager)->get(route('reports.index'))->assertOk();
    $this->actingAs($manager)->get(route('stock-takes.index'))->assertOk();
    $this->actingAs($manager)->get(route('disposals.index'))->assertOk();
    $this->actingAs($manager)->get(route('adjustments.index'))->assertOk();

    $this->actingAs($manager)->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('nav.reports'))
        ->assertSee(__('nav.stock_takes'))
        ->assertSee(__('nav.disposals'))
        ->assertSee(__('nav.adjustments'));
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
        // The label also appears in the page title and the sidebar, so assert on the link.
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
