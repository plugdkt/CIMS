<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('§7.2: the verify page works without logging in and shows only doc_no/date/status', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);

    $response = $this->get(route('verify.show', $requisition->ulid));

    $response->assertOk();
    $response->assertSee($requisition->doc_no);
    $response->assertSee($requisition->doc_date->format('d/m/Y'));
    $response->assertDontSee($student->full_name);
    $response->assertDontSee($student->email);
});

test('an unknown ulid returns 404', function () {
    $this->get(route('verify.show', '01ARZ3NDEKTSV4RRFFQ69G5FAV'))->assertStatus(404);
});
