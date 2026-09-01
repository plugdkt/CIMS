<?php

use Tests\TestCase;

// Bound to both directories (not just Feature) because some "unit" tests per spec §11.1
// (e.g. UT-06, UT-05) still need a real DB transaction/table — RefreshDatabase is opted
// into per-test, this alone doesn't force migrations on tests that don't need them.
pest()->extend(TestCase::class)->in('Unit');

// Feature tests also get the base seed data (roles/permissions/units/categories) before
// each test — RefreshDatabase only runs migrations, not seeders, and most Feature tests
// touch users/roles.
pest()->extend(TestCase::class)->beforeEach(function () {
    $this->seed();
})->in('Feature');
