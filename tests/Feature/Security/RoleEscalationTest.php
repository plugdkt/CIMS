<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ST-10: an endpoint that requires ADMIN must reject a STUDENT outright.
test('a STUDENT cannot access an ADMIN-only endpoint (ST-10)', function () {
    $student = userWithRole('STUDENT');

    $this->actingAs($student)->get(route('admin.users.index'))->assertStatus(403);
});
