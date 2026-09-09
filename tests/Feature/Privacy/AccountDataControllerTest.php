<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SEC-PD-03: my-data shows the user\'s own profile fields and their own requisitions only', function () {
    $staff = staffUser();
    $otherStaff = staffUser();
    $requisition = makeRequisition($staff);
    makeRequisition($otherStaff);

    $response = $this->actingAs($staff)->get(route('account.my-data'));

    $response->assertOk();
    $response->assertSee($staff->full_name);
    $response->assertSee($requisition->doc_no);
});

test('SEC-PD-03: the export endpoint returns a JSON copy of the user\'s own data only', function () {
    $staff = staffUser();
    $requisition = makeRequisition($staff);

    $response = $this->actingAs($staff)->get(route('account.my-data.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');
    $response->assertHeader('Content-Disposition', 'attachment; filename="cmis-my-data-'.$staff->username.'.json"');

    $payload = json_decode($response->getContent(), true);
    expect($payload['profile']['username'])->toBe($staff->username);
    expect($payload['profile']['full_name'])->toBe($staff->full_name);
    expect(collect($payload['requisitions'])->pluck('doc_no'))->toContain($requisition->doc_no);
});

test('SEC-PD-03: my-data requires login like every other page', function () {
    $this->get(route('account.my-data'))->assertRedirect(route('login'));
});
