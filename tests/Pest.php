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

// Shared by Admin/UserRoleManagerTest and Security/RoleEscalationTest (moved here so each file runs alone).
if (! function_exists('userWithRole')) {
    function userWithRole(string $roleCode): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->roles()->attach(\App\Models\Role::where('code', $roleCode)->firstOrFail());
        if (in_array($roleCode, ['LAB_MANAGER', 'AUDITOR'], true)) {
            grantItemTypes($user);
        }

        return $user;
    }
}

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

if (! function_exists('grantItemTypes')) {
    /**
     * ITM-2610-052 phase 3: warehouse managers (LAB_MANAGER/AUDITOR) are deny-by-default until they hold
     * rows in user_item_types. Test managers get both kinds unless a test narrows them with
     * ['itemTypes' => ['chemical']] (an empty list = no rows at all).
     *
     * @param  list<string>  $types
     */
    function grantItemTypes(\App\Models\User $user, array $types = ['chemical', 'consumable']): void
    {
        foreach ($types as $type) {
            \Illuminate\Support\Facades\DB::table('user_item_types')->insertOrIgnore(['user_id' => $user->id, 'item_type' => $type]);
        }
    }
}

if (! function_exists('labManagerUser')) {
    /** `itemTypes` (not a user column) narrows the kinds of stock this manager is responsible for; default both. */
    function labManagerUser(array $overrides = []): \App\Models\User
    {
        $types = $overrides['itemTypes'] ?? ['chemical', 'consumable'];
        unset($overrides['itemTypes']);

        $user = \App\Models\User::factory()->create($overrides);
        $user->roles()->attach(\App\Models\Role::where('code', 'LAB_MANAGER')->firstOrFail());
        grantItemTypes($user, $types);

        return $user;
    }
}

if (! function_exists('scientistUser')) {
    function scientistUser(array $overrides = []): \App\Models\User
    {
        $user = \App\Models\User::factory()->create($overrides);
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

// AUDITOR was repurposed 2026-09-21 into a second, independently-assignable flavor of
// branch-scoped warehouse manager (name_th "ผู้ดูแลคลัง") — same grants/scoping as
// LAB_MANAGER, see User::isBranchManager() and PermissionSeeder's own note.
if (! function_exists('auditorUser')) {
    function auditorUser(array $overrides = []): \App\Models\User
    {
        $types = $overrides['itemTypes'] ?? ['chemical', 'consumable'];
        unset($overrides['itemTypes']);

        $user = \App\Models\User::factory()->create($overrides);
        $user->roles()->attach(\App\Models\Role::where('code', 'AUDITOR')->firstOrFail());
        grantItemTypes($user, $types);

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

if (! function_exists('appendLedgerRow')) {
    /** Appends one hash-chained stock_ledger row (shared by the ledger:verify and hash-chain notification tests). */
    function appendLedgerRow(int $itemId, int $unitId, int $userId, string $qtyIn, string $balance): \App\Models\StockLedger
    {
        $hasher = app(\App\Domain\Inventory\Services\LedgerHasher::class);
        $last = \App\Models\StockLedger::where('item_id', $itemId)->orderByDesc('id')->first();

        $row = new \App\Models\StockLedger([
            'item_id' => $itemId,
            'txn_date' => now()->toDateString(),
            'txn_type' => $last === null ? 'OPENING' : 'RECEIVE',
            'qty_in_base' => $qtyIn,
            'qty_out_base' => '0.000000',
            'balance_base' => $balance,
            'display_unit_id' => $unitId,
            'created_by' => $userId,
            'created_at' => now(),
            'prev_row_hash' => $last?->row_hash,
        ]);
        $row->row_hash = $hasher->compute($row);
        $row->save();

        return $row;
    }
}

// ---------------------------------------------------------------------------------------------
// ITM-2610-052 phase 1 (home cards / section tabs) helpers.
// ---------------------------------------------------------------------------------------------

if (! function_exists('categoryId')) {
    /** Id of a seeded item category by code (CHEMICAL, DYE, MATERIAL, CONSUMABLE, GLASSWARE, ...). */
    function categoryId(string $code): int
    {
        return (int) \App\Models\ItemCategory::where('code', $code)->firstOrFail()->id;
    }
}

if (! function_exists('userWithPermissions')) {
    /**
     * A user whose only role is a throw-away role holding exactly these permission codes — for
     * "holds no card at all" / "holds exactly one card" cases no seeded role produces.
     *
     * @param  list<string>  $permissionCodes
     */
    function userWithPermissions(array $permissionCodes, array $overrides = []): \App\Models\User
    {
        $role = \App\Models\Role::create(['code' => 'T'.strtoupper(\Illuminate\Support\Str::random(10)), 'name_th' => 'บทบาททดสอบ']);
        $role->permissions()->attach(\App\Models\Permission::whereIn('code', $permissionCodes)->pluck('id'));

        $user = \App\Models\User::factory()->create($overrides);
        $user->roles()->attach($role);

        return $user->fresh();
    }
}

if (! function_exists('navUser')) {
    /**
     * Builds a user for navigation tests. `$kinds` is one kind or several joined with "+"
     * (e.g. "staff+auditor") — the first is created with its usual fixture, the rest are extra roles.
     * Every user gets a branch and a completed profile so branch/shop pages open instead of redirecting.
     * Kinds: student, staff, advisor, scientist, lab_manager, auditor, admin, nocard (a role holding
     * only `audit.view`, which opens no card).
     */
    function navUser(string $kinds, array $extra = []): \App\Models\User
    {
        $attrs = array_merge(['lab_id' => makeLab()->id, 'profile_completed_at' => now()], $extra);
        $parts = explode('+', $kinds);
        $first = array_shift($parts);

        $user = match ($first) {
            'student' => studentUser($attrs),
            'staff' => staffUser($attrs),
            'scientist' => scientistUser($attrs),
            'lab_manager' => labManagerUser($attrs),
            'auditor' => auditorUser($attrs),
            'admin' => adminUser($attrs),
            'advisor' => (function () use ($attrs) {
                $u = \App\Models\User::factory()->create(array_merge(['person_type' => 'LECTURER'], $attrs));
                $u->roles()->attach(\App\Models\Role::where('code', 'ADVISOR')->firstOrFail());

                return $u;
            })(),
            'nocard' => userWithPermissions(['audit.view'], $attrs),
        };

        foreach ($parts as $code) {
            $user->roles()->attach(\App\Models\Role::where('code', strtoupper($code))->firstOrFail());
            if (in_array(strtoupper($code), ['LAB_MANAGER', 'AUDITOR'], true)) {
                grantItemTypes($user);
            }
        }

        return $user->fresh();
    }
}

if (! function_exists('requisitionWithLines')) {
    /**
     * A requisition with one line per given category code (e.g. ['CHEMICAL'], ['GLASSWARE', 'DYE']).
     * `request_type` (the SET the requester ticked) is deliberately left at makeRequisition's default
     * unless overridden, because ITM-2610-052 filters on the lines' real categories, not on that box.
     *
     * @param  list<string>  $categoryCodes
     */
    function requisitionWithLines(\App\Models\User $requester, array $categoryCodes, array $overrides = []): \App\Models\Requisition
    {
        $requisition = makeRequisition($requester, $overrides);
        $g = \App\Models\Unit::where('code', 'g')->firstOrFail();

        foreach (array_values($categoryCodes) as $i => $code) {
            $requisition->items()->create([
                'line_no' => $i + 1,
                'item_id' => makeItem(['category_id' => categoryId($code)])->id,
                'qty_requested' => '5.000000',
                'unit_id' => $g->id,
                'qty_requested_base' => '5.000000',
            ]);
        }

        return $requisition->fresh();
    }
}

if (! function_exists('htmlXPath')) {
    function htmlXPath(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }
}

if (! function_exists('homeCards')) {
    /**
     * The cards on the home page, in document order: title, link, and the badge texts on each.
     *
     * @return list<array{title: string, href: string, badges: list<string>}>
     */
    function homeCards(string $html): array
    {
        $xp = htmlXPath($html);
        $cards = [];

        foreach ($xp->query('//main//a[contains(concat(" ", normalize-space(@class), " "), " rounded-xl ")]') as $a) {
            $badges = [];
            foreach ($xp->query('.//span[contains(@class, "rounded-full")]', $a) as $badge) {
                $badges[] = trim((string) preg_replace('/\s+/u', ' ', $badge->textContent));
            }
            $cards[] = [
                'title' => trim((string) $xp->evaluate('string(.//span[contains(@class, "text-base")][1])', $a)),
                'href' => $a->getAttribute('href'),
                'badges' => $badges,
            ];
        }

        return $cards;
    }
}

if (! function_exists('layoutNav')) {
    /**
     * What the shared layout drew around a page (ITM-2610-052, sidebar layout): the <aside> with its
     * <nav aria-label=tabs_label> holding the "back to home" link, the section title (div.font-display)
     * and the menu links. `tabs` excludes the back link; `hasSidebar` is false on pages with no <aside>
     * (home page, single-card accounts' ... pages outside every card for them).
     *
     * @return array{title: string, tabs: list<array{label: string, href: string, active: bool}>, hasSidebar: bool, back: string|null, logo: string}
     */
    function layoutNav(string $html): array
    {
        $xp = htmlXPath($html);
        $backText = (string) __('nav.back_home');
        $tabs = [];
        $back = null;
        $title = '';
        $asides = $xp->query('//aside[@id="app-sidebar"]');
        $hasSidebar = $asides !== false && $asides->length > 0;

        if ($hasSidebar) {
            $navs = $xp->query('.//nav[@aria-label="'.__('nav.tabs_label').'"]', $asides->item(0));
            if ($navs !== false && $navs->length > 0) {
                $nav = $navs->item(0);
                $title = trim((string) $xp->evaluate('string(./div[contains(@class, "font-display")][1])', $nav));
                foreach ($xp->query('.//a', $nav) as $a) {
                    $label = trim((string) preg_replace('/\s+/u', ' ', $a->textContent));
                    if ($label === $backText) {
                        $back = $a->getAttribute('href');

                        continue;
                    }
                    $tabs[] = ['label' => $label, 'href' => $a->getAttribute('href'), 'active' => $a->getAttribute('aria-current') === 'page'];
                }
            }
        }

        return [
            'title' => $title,
            'tabs' => $tabs,
            'hasSidebar' => $hasSidebar,
            'back' => $back,
            'logo' => (string) $xp->evaluate('string(//header//a[not(@id)][1]/@href)'),
        ];
    }
}

if (! function_exists('managedBadge')) {
    /**
     * The "ดูแล: ..." badge a warehouse manager's card shows (ITM-2610-052 phase 3), built from the real
     * lang keys. Kinds are listed in the order MenuRegistry lists them (chemical before consumable).
     *
     * @param  list<string>  $kinds  ItemType slugs
     */
    function managedBadge(array $kinds): string
    {
        return (string) __('nav.badge_managed_types', [
            'types' => implode(' + ', array_map(fn (string $k) => (string) __('requisitions.request_type_'.$k), $kinds)),
        ]);
    }
}

// ---------------------------------------------------------------------------------------------
// ITM-2610-051 (borrow / return module), step 1 helpers.
// ---------------------------------------------------------------------------------------------

if (! function_exists('makeCountItem')) {
    /** An item that may be lent: base unit of dimension COUNT (pcs). Pass a unit code to build a non-lendable one. */
    function makeCountItem(array $overrides = [], string $unitCode = 'pcs'): \App\Models\Item
    {
        return makeItem(array_merge([
            'name_th' => 'อุปกรณ์ให้ยืมทดสอบ',
            'category_id' => categoryId('MATERIAL'),
            'base_unit_id' => \App\Models\Unit::where('code', $unitCode)->firstOrFail()->id,
        ], $overrides));
    }
}

if (! function_exists('loanService')) {
    function loanService(): \App\Domain\Loan\Services\LoanLedgerService
    {
        return app(\App\Domain\Loan\Services\LoanLedgerService::class);
    }
}

if (! function_exists('registerLoanAsset')) {
    /** Registers a POOL (default) or UNIT asset through the real service, as the given manager. */
    function registerLoanAsset(\App\Models\User $actor, ?\App\Models\Lab $lab = null, string $mode = 'POOL', int|string $qty = 10, ?\App\Models\Item $item = null): \App\Models\LoanAsset
    {
        return loanService()->register($actor, [
            'lab_id' => ($lab ?? \App\Models\Lab::findOrFail($actor->lab_id))->id,
            'item_id' => ($item ?? makeCountItem())->id,
            'tracking_mode' => $mode,
            'qty' => $mode === 'UNIT' ? 1 : $qty,
        ]);
    }
}

if (! function_exists('loanManagers')) {
    /** Two managers of one branch (needed: adjust/retire require a different approver). @return array{\App\Models\Lab, \App\Models\User, \App\Models\User} */
    function loanManagers(): array
    {
        $lab = makeLab();

        return [$lab, auditorUser(['lab_id' => $lab->id]), labManagerUser(['lab_id' => $lab->id])];
    }
}
