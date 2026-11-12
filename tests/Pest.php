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
    /**
     * ITM-2610-062: pass `$labId` to put the container in a storage place of that branch. Only containers whose place
     * belongs to the branch count as working stock for the shop / the issue page (a container with no place does not).
     */
    function stockedContainer(int $itemId, string $qtyBase, \App\Models\User $receiver, ?int $labId = null): \App\Models\Container
    {
        $container = makeContainer(['item_id' => $itemId, 'remaining_qty_base' => '0.000000']);
        if ($labId !== null) {
            $container->update(['location_id' => makeLocationForLab(\App\Models\Lab::findOrFail($labId))->id]);
        }
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

// ---------------------------------------------------------------------------------------------
// ITM-2610-051 (borrow / return module), step 2 helpers (request / approve / cancel).
// ---------------------------------------------------------------------------------------------

if (! function_exists('loanBranch')) {
    /**
     * A branch with one POOL asset of `$qty` pieces. The manager who registers it is deactivated afterwards, so the
     * branch roster the test reasons about (who may approve, who is notified) holds only the people the test adds.
     *
     * @return array{\App\Models\Lab, \App\Models\LoanAsset}
     */
    function loanBranch(int $qty = 10): array
    {
        $lab = makeLab();
        $stocker = auditorUser(['lab_id' => $lab->id]);
        $asset = registerLoanAsset($stocker, $lab, 'POOL', $qty);
        $stocker->update(['is_active' => false]);

        return [$lab, $asset];
    }
}

if (! function_exists('loanDates')) {
    /** @return array{loan_date: string, due_date: string} pick-up `$startInDays` from today, due `$days` after that */
    function loanDates(int $days = 7, int $startInDays = 0): array
    {
        $from = now()->startOfDay()->addDays($startInDays);

        return ['loan_date' => $from->format('Y-m-d'), 'due_date' => $from->copy()->addDays($days)->format('Y-m-d')];
    }
}

if (! function_exists('loanData')) {
    /** @return array<string, mixed> the header fields LoanService::createDraft() takes */
    function loanData(array $overrides = []): array
    {
        return array_merge(['purpose_type' => 'TEACHING'], loanDates(), $overrides);
    }
}

if (! function_exists('draftLoan')) {
    /**
     * A DRAFT loan made through the real LoanService. `$actor` (counter staff) makes it on behalf of `$borrower`.
     *
     * @param  \App\Models\LoanAsset|list<\App\Models\LoanAsset>  $assets  every asset gets `$qty` pieces
     */
    function draftLoan(\App\Models\User $borrower, \App\Models\LoanAsset|array $assets, int|string $qty = 2, ?\App\Models\User $actor = null, array $data = []): \App\Models\Loan
    {
        $assets = $assets instanceof \App\Models\LoanAsset ? [$assets] : $assets;
        $lines = [];
        foreach ($assets as $asset) {
            $lines[$asset->ulid] = (string) $qty;
        }

        $resolver = app(\App\Domain\Loan\Services\BorrowerResolver::class);
        $context = $actor === null ? $resolver->forSelf($borrower->fresh()) : $resolver->forOnBehalf($actor->fresh(), $borrower->id);

        return app(\App\Domain\Loan\Services\LoanService::class)->createDraft($actor ?? $borrower, $context, loanData($data), $lines);
    }
}

if (! function_exists('submittedLoan')) {
    function submittedLoan(\App\Models\User $borrower, \App\Models\LoanAsset|array $assets, int|string $qty = 2, ?\App\Models\User $actor = null, array $data = []): \App\Models\Loan
    {
        $loan = draftLoan($borrower, $assets, $qty, $actor, $data);

        return app(\App\Domain\Loan\Services\LoanService::class)->submit(($actor ?? $borrower)->fresh(), $loan);
    }
}

if (! function_exists('forceLoanStatus')) {
    /** Puts a loan into a status (and columns) directly, for states step 2 cannot reach yet (hand-out etc.). */
    function forceLoanStatus(\App\Models\Loan $loan, string $status, array $extra = []): \App\Models\Loan
    {
        \Illuminate\Support\Facades\DB::table('loans')->where('id', $loan->id)->update(array_merge(['status' => $status], $extra));

        return $loan->fresh();
    }
}

if (! function_exists('loanMailCount')) {
    /** Generic notification emails (sent or queued) addressed to this user. */
    function loanMailCount(\App\Models\User $user): int
    {
        return \Illuminate\Support\Facades\Mail::sent(\App\Mail\NotificationMail::class, fn ($m) => $m->hasTo($user->email))->count()
            + \Illuminate\Support\Facades\Mail::queued(\App\Mail\NotificationMail::class, fn ($m) => $m->hasTo($user->email))->count();
    }
}

if (! function_exists('loanNotices')) {
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Notification> */
    function loanNotices(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\Notification::where('type', $type)->get();
    }
}

if (! function_exists('loanAudit')) {
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditLog> */
    function loanAudit(string $action, \App\Models\Loan $loan): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\AuditLog::where('action', $action)->where('entity_type', \App\Models\Loan::class)->where('entity_id', $loan->id)->get();
    }
}

if (! function_exists('loanExtraAsset')) {
    /** A second POOL asset in a branch made by loanBranch() (its register clerk is deactivated again). */
    function loanExtraAsset(\App\Models\Lab $lab, int $qty = 5, ?\App\Models\Item $item = null, string $mode = 'POOL'): \App\Models\LoanAsset
    {
        $stocker = auditorUser(['lab_id' => $lab->id]);
        $asset = registerLoanAsset($stocker, $lab, $mode, $qty, $item);
        $stocker->update(['is_active' => false]);

        return $asset;
    }
}

if (! function_exists('loanManagerPost')) {
    /** POST the MANAGER step (logged-in approver) of a loan. */
    function loanManagerPost($test, \App\Models\User $user, \App\Models\Loan $loan, array $payload = ['decision' => 'APPROVE'])
    {
        return $test->actingAs($user)->post(route('loans.manager-decide', $loan), $payload);
    }
}

if (! function_exists('loanAdvisorPost')) {
    /** POST the ADVISOR step (logged-in advisor) of a loan. */
    function loanAdvisorPost($test, \App\Models\User $user, \App\Models\Loan $loan, array $payload = ['decision' => 'APPROVE'])
    {
        return $test->actingAs($user)->post(route('loans.advisor-decide', $loan), $payload);
    }
}

if (! function_exists('loanPayload')) {
    /** The request form's fields: header (today + 7 days by default) and `qty` = asset ulid => quantity. */
    function loanPayload(array $qty, array $extra = []): array
    {
        return array_merge(loanData(), ['qty' => $qty], $extra);
    }
}

if (! function_exists('loanCancelPost')) {
    /** POST the cancel form of a loan as the given user. */
    function loanCancelPost($test, \App\Models\User $user, \App\Models\Loan $loan, array $payload = [])
    {
        return $test->actingAs($user)->post(route('loans.cancel', $loan), $payload);
    }
}

// ---------------------------------------------------------------------------------------------
// ITM-2610-051 (borrow / return module), step 3 helpers (hand-out / return / renew).
// ---------------------------------------------------------------------------------------------

if (! function_exists('approvedLoan')) {
    /**
     * An APPROVED loan through the real services: a STUDENT's by the advisor named on it, anybody else's by
     * `$approver` (default: a throw-away scientist of the branch, deactivated again so the branch roster the test
     * reasons about holds only the people the test adds).
     *
     * @param  \App\Models\LoanAsset|list<\App\Models\LoanAsset>  $assets
     */
    function approvedLoan(\App\Models\User $borrower, \App\Models\LoanAsset|array $assets, int|string $qty = 2, ?\App\Models\User $approver = null, array $data = []): \App\Models\Loan
    {
        $loan = submittedLoan($borrower, $assets, $qty, null, $data);
        $service = app(\App\Domain\Loan\Services\LoanApprovalService::class);

        if ($loan->isStudentBorrower()) {
            $advisor = \App\Models\User::with('roles.permissions')->findOrFail($loan->advisor_id);

            return $service->advisorDecide($loan, $advisor, 'APPROVE')->fresh();
        }

        $throwAway = $approver === null;
        $approver ??= scientistUser(['lab_id' => $loan->lab_id]);
        $approved = $service->managerDecide($loan, $approver->fresh(), 'APPROVE')->fresh();
        if ($throwAway) {
            $approver->update(['is_active' => false]);
        }

        return $approved;
    }
}

if (! function_exists('loanConditions')) {
    /** @return array<int, array{condition: string, note: string|null}> line_no => the condition written down for every line */
    function loanConditions(\App\Models\Loan $loan, string $condition = 'GOOD', ?string $note = null): array
    {
        $rows = [];
        foreach ($loan->lines()->get() as $line) {
            $rows[$line->line_no] = ['condition' => $condition, 'note' => $note];
        }

        return $rows;
    }
}

if (! function_exists('handedOutLoan')) {
    /**
     * A loan that is ON_LOAN, handed out through the real LoanCirculationService by `$handler` (default: a throw-away
     * scientist of the branch, deactivated again afterwards). Every line goes out in GOOD condition.
     *
     * @param  \App\Models\LoanAsset|list<\App\Models\LoanAsset>  $assets
     */
    function handedOutLoan(\App\Models\User $borrower, \App\Models\LoanAsset|array $assets, int|string $qty = 2, ?\App\Models\User $handler = null, array $data = []): \App\Models\Loan
    {
        $loan = approvedLoan($borrower, $assets, $qty, null, $data);

        $throwAway = $handler === null;
        $handler ??= scientistUser(['lab_id' => $loan->lab_id]);
        $out = app(\App\Domain\Loan\Services\LoanCirculationService::class)->handOut($handler->fresh(), $loan, loanConditions($loan))->fresh();
        if ($throwAway) {
            $handler->update(['is_active' => false]);
        }

        return $out;
    }
}

if (! function_exists('loanHandoutPost')) {
    /** POST the hand-out form (every line GOOD unless `$lines` says otherwise). */
    function loanHandoutPost($test, \App\Models\User $user, \App\Models\Loan $loan, ?array $lines = null)
    {
        return $test->actingAs($user)->post(route('loans.handout', $loan), ['lines' => $lines ?? loanConditions($loan)]);
    }
}

if (! function_exists('loanReturnPost')) {
    /** POST the return form. `$lines` = line_no => ['qty' => .., 'condition' => .., 'note' => .., 'outcome' => ..]. */
    function loanReturnPost($test, \App\Models\User $user, \App\Models\Loan $loan, array $lines)
    {
        return $test->actingAs($user)->post(route('loans.return', $loan), ['lines' => $lines]);
    }
}

if (! function_exists('loanCounterState')) {
    /**
     * Everything a hand-out / return may change, for "nothing was written" checks: the loan's status and dates, its
     * lines, the quantities of its assets, how many movements those assets have, and how many counter audit rows exist.
     *
     * @return array<string, mixed>
     */
    function loanCounterState(\App\Models\Loan $loan): array
    {
        $fresh = $loan->fresh();
        $lines = $fresh->lines()->get();
        $assetIds = $lines->pluck('loan_asset_id');

        return [
            'status' => $fresh->status,
            'handed_out_at' => $fresh->handed_out_at?->toDateTimeString(),
            'closed_at' => $fresh->closed_at?->toDateTimeString(),
            'lines' => $lines->map(fn ($l) => [$l->line_no, $l->qty_returned_ok, $l->condition_out, $l->condition_out_note])->all(),
            'assets' => \App\Models\LoanAsset::whereIn('id', $assetIds)->orderBy('id')->get()
                ->map(fn ($a) => [$a->id, $a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->status])->all(),
            'movements' => \App\Models\LoanMovement::whereIn('loan_asset_id', $assetIds)->count(),
            'audit' => \App\Models\AuditLog::where('entity_type', \App\Models\Loan::class)->where('entity_id', $loan->id)
                ->whereIn('action', ['LOAN_HANDOUT', 'LOAN_SELF_HANDOUT', 'LOAN_RETURN', 'LOAN_SELF_RETURN'])->count(),
        ];
    }
}

if (! function_exists('assertLoanChainIntact')) {
    /** Every movement of the asset links to the one before it and hashes to its own row_hash (BR-L16). */
    function assertLoanChainIntact(\App\Models\LoanAsset $asset): void
    {
        $hasher = app(\App\Domain\Loan\Services\LoanLedgerHasher::class);
        $prev = null;
        foreach (\App\Models\LoanMovement::where('loan_asset_id', $asset->id)->orderBy('id')->get() as $row) {
            expect($row->prev_row_hash)->toBe($prev, "movement {$row->id}: link to the previous row");
            expect($row->row_hash)->toBe($hasher->compute($row), "movement {$row->id}: own hash");
            $prev = $row->row_hash;
        }
        expect($hasher->verifyChain($asset->id))->toBe(['ok' => true, 'broken_at' => null]);
    }
}

if (! function_exists('loanRenewAudit')) {
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditLog> every renewal audit row of the loan */
    function loanRenewAudit(\App\Models\Loan $loan): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\AuditLog::where('entity_type', \App\Models\Loan::class)->where('entity_id', $loan->id)
            ->where('action', 'like', 'LOAN_%RENEW%')->orderBy('id')->get();
    }
}

// ---------------------------------------------------------------------------------------------
// ITM-2610-051 (borrow / return module), step 4 helpers (damage / loss incidents and their results).
// ---------------------------------------------------------------------------------------------

if (! function_exists('recordLoanIncident')) {
    /**
     * Takes `$qty` pieces of one line back as DAMAGED / LOST through the real LoanCirculationService (the only way an
     * incident is made) and returns the PENDING incident.
     */
    function recordLoanIncident(\App\Models\Loan $loan, \App\Models\User $recorder, string $kind = 'DAMAGED', int|string $qty = 1, int $lineNo = 1, string $description = 'หน้าจอแตกร้าวใช้งานต่อไม่ได้'): \App\Models\LoanIncident
    {
        $out = app(\App\Domain\Loan\Services\LoanCirculationService::class)->receiveReturn(
            $recorder->fresh(),
            $loan->fresh(),
            [$lineNo => [$kind === 'LOST' ? 'qty_lost' : 'qty_damaged' => (string) $qty, 'incident_note' => $description]],
        );

        return \App\Models\LoanIncident::findOrFail($out->getRelation('recordedIncidents')->firstOrFail()->id);
    }
}

if (! function_exists('attachLoanIncidentFile')) {
    /** An attachment row of an incident, written directly (no upload): the outside letter (LOAN_DOC) by default. */
    function attachLoanIncidentFile(\App\Models\LoanIncident $incident, \App\Models\User $uploader, string $docType = 'LOAN_DOC', string $name = 'memo.pdf'): \App\Models\Attachment
    {
        $stored = (string) \Illuminate\Support\Str::uuid().'.'.pathinfo($name, PATHINFO_EXTENSION);

        return \App\Models\Attachment::create([
            'owner_type' => $incident->getMorphClass(),
            'owner_id' => $incident->id,
            'doc_type' => $docType,
            'original_name' => $name,
            'stored_name' => $stored,
            'mime_type' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'image/png',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $stored),
            'version' => 1 + (int) \App\Models\Attachment::where('owner_type', $incident->getMorphClass())->where('owner_id', $incident->id)->where('doc_type', $docType)->max('version'),
            'uploaded_by' => $uploader->id,
        ]);
    }
}

if (! function_exists('loanResolveData')) {
    /**
     * The fields of the "record the result" form, complete for `$resolution` (a reference of 5+ and a note of 10+).
     *
     * @return array<string, mixed>
     */
    function loanResolveData(string $resolution, array $extra = []): array
    {
        return array_merge([
            'resolution' => $resolution,
            'external_ref' => 'อว 7301/0123',
            'resolution_note' => 'ผู้บริหารพิจารณาตามบันทึกข้อความแล้ว',
        ], $extra);
    }
}

if (! function_exists('loanResolvePost')) {
    /** POST the "record the result" form of an incident. */
    function loanResolvePost($test, \App\Models\User $user, \App\Models\LoanIncident $incident, array $payload)
    {
        return $test->actingAs($user)->post(route('loans.incidents.resolve', $incident), $payload);
    }
}

if (! function_exists('loanIncidentState')) {
    /**
     * Everything recording an incident or its result may change, for "nothing was written" checks.
     *
     * @return array<string, mixed>
     */
    function loanIncidentState(\App\Models\Loan $loan): array
    {
        $fresh = $loan->fresh();
        $lines = $fresh->lines()->get();
        $assetIds = $lines->pluck('loan_asset_id');
        $incidentIds = \App\Models\LoanIncident::where('loan_id', $loan->id)->pluck('id');

        return [
            'status' => $fresh->status,
            'closed_at' => $fresh->closed_at?->toDateTimeString(),
            'lines' => $lines->map(fn ($l) => [$l->line_no, $l->qty_returned_ok, $l->qty_returned_damaged, $l->qty_lost])->all(),
            'assets' => \App\Models\LoanAsset::whereIn('id', $assetIds)->orderBy('id')->get()
                ->map(fn ($a) => [$a->id, $a->qty_total, $a->qty_on_loan, $a->qty_unavailable, $a->status])->all(),
            'movements' => \App\Models\LoanMovement::whereIn('loan_asset_id', $assetIds)->count(),
            'incidents' => \App\Models\LoanIncident::where('loan_id', $loan->id)->orderBy('id')->get()
                ->map(fn ($i) => [$i->id, $i->kind, $i->qty, $i->resolution, $i->disposition, $i->resolved_by, $i->external_ref, $i->resolution_note, $i->value_snapshot])->all(),
            'audit' => \App\Models\AuditLog::query()
                ->whereIn('action', ['LOAN_RETURN', 'LOAN_SELF_RETURN', 'LOAN_INCIDENT_RECORD', 'LOAN_INCIDENT_RESOLVE', 'LOAN_WRITE_OFF', 'LOAN_REPLACE_IN'])
                ->where(function ($q) use ($loan, $assetIds, $incidentIds) {
                    $q->where(fn ($w) => $w->where('entity_type', \App\Models\Loan::class)->where('entity_id', $loan->id))
                        ->orWhere(fn ($w) => $w->where('entity_type', \App\Models\LoanAsset::class)->whereIn('entity_id', $assetIds))
                        ->orWhere(fn ($w) => $w->where('entity_type', \App\Models\LoanIncident::class)->whereIn('entity_id', $incidentIds));
                })->count(),
        ];
    }
}

if (! function_exists('loanIncidentAudit')) {
    /** @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AuditLog> */
    function loanIncidentAudit(string $action, \App\Models\LoanIncident $incident): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Models\AuditLog::where('action', $action)->where('entity_type', \App\Models\LoanIncident::class)->where('entity_id', $incident->id)->orderBy('id')->get();
    }
}

// ---------------------------------------------------------------------------------------------
// ITM-2610-062 (IMS -> working stock) helpers.
// ---------------------------------------------------------------------------------------------

if (! function_exists('makeImsLot')) {
    /**
     * An IMS lot written directly (no receipt document): `$packs` whole packs of `$packSize` base units each in branch
     * `$labId`. `$packSize = null` makes a lot with no recorded pack size, then `$packs` is the number of base units.
     */
    function makeImsLot(int $labId, \App\Models\Item $item, string $packs = '4', ?string $packSize = '500', array $overrides = []): \App\Models\ImsLot
    {
        $total = $packSize === null ? $packs : bcmul($packs, $packSize, 6);

        return \App\Models\ImsLot::create(array_merge([
            'item_id' => $item->id,
            'lab_id' => $labId,
            'lot_no' => 'LOT-'.fake()->unique()->numerify('######'),
            'pack_size_base' => $packSize,
            'qty_received_base' => $total,
            'qty_remaining_base' => $total,
            'received_at' => now()->toDateString(),
        ], $overrides));
    }
}

if (! function_exists('consumableItem')) {
    /** A catalog item of the CONSUMABLE kind (category MATERIAL, not chemical), base unit g. */
    function consumableItem(array $overrides = []): \App\Models\Item
    {
        return makeItem(array_merge(['category_id' => categoryId('MATERIAL')], $overrides));
    }
}

// ---------------------------------------------------------------------------------------------
// ITM-2610-063 (third kind of stock: science / loan-and-return) helpers.
// ---------------------------------------------------------------------------------------------

if (! function_exists('scienceItem')) {
    /** A catalog item of the SCIENCE kind (category LOAN_SCIENCE, not chemical), base unit g. */
    function scienceItem(array $overrides = []): \App\Models\Item
    {
        return makeItem(array_merge(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)], $overrides));
    }
}

if (! function_exists('requisitionWithItem')) {
    /** A requisition (given status) holding one line for exactly this item. */
    function requisitionWithItem(\App\Models\Item $item, string $status = 'SUBMITTED', ?\App\Models\User $requester = null): \App\Models\Requisition
    {
        $requisition = makeRequisition($requester ?? staffUser(), ['status' => $status]);
        $requisition->items()->create([
            'line_no' => 1,
            'item_id' => $item->id,
            'qty_requested' => '5.000000',
            'unit_id' => \App\Models\Unit::where('code', 'g')->firstOrFail()->id,
            'qty_requested_base' => '5.000000',
        ]);

        return $requisition->fresh();
    }
}
