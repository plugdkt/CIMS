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

if (! function_exists('makeItem')) {
    function makeItem(array $overrides = []): \App\Models\Item
    {
        $category = \App\Models\ItemCategory::where('code', 'CHEMICAL')->firstOrFail();
        $baseUnit = \App\Models\Unit::where('code', 'g')->firstOrFail();

        return \App\Models\Item::create(array_merge([
            'item_code' => 'CHM-'.fake()->unique()->numerify('#####'),
            'category_id' => $category->id,
            'name_th' => 'โซเดียมไฮดรอกไซด์',
            'name_en' => 'Sodium Hydroxide',
            'cas_no' => '1310-73-2',
            'base_unit_id' => $baseUnit->id,
            'is_active' => true,
        ], $overrides));
    }
}

if (! function_exists('labManagerUser')) {
    function labManagerUser(array $overrides = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create($overrides);
        $user->roles()->attach(\App\Models\Role::where('code', 'LAB_MANAGER')->firstOrFail());

        return $user;
    }
}

if (! function_exists('scientistUser')) {
    function scientistUser(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->roles()->attach(\App\Models\Role::where('code', 'SCIENTIST')->firstOrFail());

        return $user;
    }
}

if (! function_exists('adminUser')) {
    function adminUser(array $overrides = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create($overrides);
        $user->roles()->attach(\App\Models\Role::where('code', 'ADMIN')->firstOrFail());

        return $user;
    }
}

if (! function_exists('makeLab')) {
    function makeLab(array $overrides = []): \App\Models\Lab
    {
        return \App\Models\Lab::create(array_merge([
            'code' => 'LAB-'.fake()->unique()->numerify('####'),
            'name_th' => 'ห้องปฏิบัติการทดสอบ',
            'is_active' => true,
        ], $overrides));
    }
}

if (! function_exists('makeLocationForLab')) {
    function makeLocationForLab(\App\Models\Lab $lab): \App\Models\Location
    {
        return \App\Models\Location::create([
            'code' => 'BLD-'.fake()->unique()->numerify('####'),
            'name' => 'อาคารทดสอบ',
            'level_type' => 'BUILDING',
            'lab_id' => $lab->id,
        ]);
    }
}

if (! function_exists('makeRequisition')) {
    function makeRequisition(\App\Models\User $requester, array $overrides = []): \App\Models\Requisition
    {
        $lab = makeLab();

        return \App\Models\Requisition::create(array_merge([
            'doc_no' => 'REQ-2569-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'lab_id' => $requester->lab_id ?? $lab->id,
            'doc_date' => now()->toDateString(),
            'requester_id' => $requester->id,
            'requester_name' => $requester->full_name,
            'requester_status' => $requester->person_type,
            'requester_phone' => $requester->phone_encrypted,
            'student_code' => $requester->person_type === 'STUDENT' ? $requester->person_code_encrypted : null,
            'program' => $requester->program,
            'faculty' => $requester->faculty,
            'advisor_id' => $requester->advisor_id,
            'request_type' => ['CHEMICAL'],
            'purpose_type' => 'TEACHING',
            'status' => 'DRAFT',
        ], $overrides));
    }
}

if (! function_exists('studentUser')) {
    function studentUser(array $overrides = []): \App\Models\User
    {
        $advisor = \App\Models\User::factory()->create(['person_type' => 'LECTURER']);
        $advisor->roles()->attach(\App\Models\Role::where('code', 'ADVISOR')->firstOrFail());

        $user = \App\Models\User::factory()->create(array_merge([
            'person_type' => 'STUDENT',
            'phone_encrypted' => '0812345678',
            'person_code_encrypted' => '6512345',
            'program' => 'เคมี',
            'faculty' => 'วิทยาศาสตร์',
            'advisor_id' => $advisor->id,
            'profile_completed_at' => now(),
        ], $overrides));
        $user->roles()->attach(\App\Models\Role::where('code', 'STUDENT')->firstOrFail());

        return $user;
    }
}

if (! function_exists('staffUser')) {
    function staffUser(array $overrides = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(array_merge([
            'person_type' => 'STAFF',
            'phone_encrypted' => '0898765432',
            'program' => null,
            'faculty' => 'วิทยาศาสตร์',
            'profile_completed_at' => now(),
        ], $overrides));
        $user->roles()->attach(\App\Models\Role::where('code', 'STAFF')->firstOrFail());

        return $user;
    }
}

if (! function_exists('makeContainer')) {
    function makeContainer(array $overrides = []): \App\Models\Container
    {
        $itemId = $overrides['item_id'] ?? null;
        unset($overrides['item_id']);
        $itemId = $itemId ?? makeItem()->id;

        return \App\Models\Container::create(array_merge([
            'item_id' => $itemId,
            'barcode' => 'BC-'.fake()->unique()->numerify('########'),
            'received_at' => now()->toDateString(),
            'initial_qty_base' => '100.000000',
            'remaining_qty_base' => '0.000000',
            'status' => 'SEALED',
        ], $overrides));
    }
}

if (! function_exists('approvedRequisition')) {
    function approvedRequisition(\App\Models\User $requester, string $qtyRequested = '100.000000'): \App\Models\Requisition
    {
        $requisition = makeRequisition($requester);
        $item = makeItem();
        $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
        app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $g, $qtyRequested);
        $requisition->update(['status' => 'APPROVED']);

        return $requisition->fresh(['items']);
    }
}

if (! function_exists('stockedContainer')) {
    function stockedContainer(int $itemId, string $qtyBase, \App\Models\User $receiver): \App\Models\Container
    {
        $container = makeContainer(['item_id' => $itemId, 'remaining_qty_base' => '0.000000']);
        $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
        app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
            $container->id,
            $qtyBase,
            new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $receiver->id)
        );

        return $container->fresh();
    }
}

if (! function_exists('stockItemInLab')) {
    /** The add-line endpoint requires the item to actually have stock in the requisition's own lab. */
    function stockItemInLab(int $itemId, int $labId): void
    {
        $location = makeLocationForLab(\App\Models\Lab::findOrFail($labId));
        makeContainer([
            'item_id' => $itemId,
            'location_id' => $location->id,
            'status' => 'SEALED',
            'remaining_qty_base' => '1000.000000',
        ]);
    }
}

if (! function_exists('studentUserWithLab')) {
    /** @return array{\App\Models\User, \App\Models\Lab} */
    function studentUserWithLab(): array
    {
        $lab = makeLab();
        $student = studentUser(['lab_id' => $lab->id]);

        return [$student, $lab];
    }
}

if (! function_exists('submittedRequisition')) {
    function submittedRequisition(\App\Models\User $requester, array $overrides = []): \App\Models\Requisition
    {
        $requisition = makeRequisition($requester, $overrides);
        $item = makeItem();
        $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
        $requisition->items()->create([
            'line_no' => 1,
            'item_id' => $item->id,
            'qty_requested' => '5.000000',
            'unit_id' => $g->id,
            'qty_requested_base' => '5.000000',
        ]);
        $requisition->update(['status' => 'SUBMITTED', 'submitted_at' => now()]);

        return $requisition->fresh();
    }
}

if (! function_exists('makeDraftGrn')) {
    function makeDraftGrn(array $overrides = []): \App\Models\GoodsReceipt
    {
        return \App\Models\GoodsReceipt::create(array_merge([
            'doc_no' => 'GRN-2569-'.fake()->unique()->numerify('#####'),
            'receipt_date' => now()->toDateString(),
            'lab_id' => makeLab()->id,
            'status' => 'DRAFT',
            'received_by' => \App\Models\User::factory()->create()->id,
        ], $overrides));
    }
}

if (! function_exists('issueOneLine')) {
    function issueOneLine(\App\Models\Requisition $requisition, string $qty = '10.000000'): void
    {
        $line = $requisition->items->first();
        $container = stockedContainer($line->item_id, '100.000000', staffUser());
        $scientist = scientistUser();
        $g = \App\Models\Unit::where('code', 'g')->firstOrFail();

        app(\App\Domain\Requisition\Services\IssueService::class)
            ->issue($line, $container, $qty, $g, $scientist, $requisition->requester, 'sig-hash');
    }
}
