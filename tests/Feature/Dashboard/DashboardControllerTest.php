<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest sees the plain welcome page at /', function () {
    $this->get('/')->assertOk()->assertViewIs('welcome');
});

test('§7.9: a logged-in STUDENT sees the dashboard with a pending-requisitions card but no report.view analytics', function () {
    $student = studentUser();

    $response = $this->actingAs($student)->get('/');

    $response->assertOk()->assertViewIs('home');
    $response->assertViewHas('pendingRequisitions');
    $response->assertViewMissing('belowReorderCount');
    $response->assertViewMissing('topItems');
    $response->assertSee(__('home.pending_requisitions'));
    $response->assertDontSee(__('home.below_reorder'));
});

test('§7.9: a SCIENTIST (report.view) sees the full dashboard with all analytics cards', function () {
    $scientist = scientistUser();

    $response = $this->actingAs($scientist)->get('/');

    $response->assertOk()->assertViewIs('home');
    $response->assertViewHas('belowReorderCount');
    $response->assertViewHas('expiringCount');
    $response->assertViewHas('topItems');
    $response->assertViewHas('monthlySeries');
    $response->assertSee(__('home.below_reorder'));
    $response->assertSee(__('home.monthly_chart_title'));
});
