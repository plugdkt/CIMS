<?php

use App\Domain\Navigation\MenuRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1 (plan 1.3): where every old sidebar entry went. The rule from the plan:
 * every page that used to be in the menu must still have at least one way in for the roles that
 * had it, and a page appears in only the section that suits the role (the warehouse card for
 * managers, the back office for ADMIN, the requesting cards for everyone else).
 *
 * Tab lists are asserted exactly, so a link that silently disappears (or appears for the wrong
 * role) fails here. Labels are compared through lang keys, so wording changes do not break this.
 */

if (! function_exists('tabLabels')) {
    /** @return list<string> */
    function tabLabels(\App\Models\User $user, string $section): array
    {
        return array_column(app(MenuRegistry::class)->tabsFor($user, $section), 'label');
    }
}

if (! function_exists('lbl')) {
    /** @param  list<string>  $keys */
    function lbl(array $keys): array
    {
        return array_map(fn (string $k) => (string) __('nav.'.$k), $keys);
    }
}

// --- requesting cards --------------------------------------------------------------------------

test('a plain requester (STUDENT/STAFF/ADVISOR/SCIENTIST) finds every page they used to open, in the two requesting cards', function (string $kind) {
    $user = navUser($kind);

    $common = ['tab_shop', 'tab_cart', 'tab_my_requisitions'];
    $rest = ['tab_working_stock', 'tab_ims_browse', 'tab_items_view'];

    expect(tabLabels($user, MenuRegistry::CHEMICAL))->toBe(lbl([...$common, 'chemical_lookup', ...$rest]));
    expect(tabLabels($user, MenuRegistry::CONSUMABLE))->toBe(lbl([...$common, ...$rest]));
})->with(['student', 'staff', 'advisor', 'scientist']);

test('a warehouse manager (LAB_MANAGER/AUDITOR) gets no duplicate of what the warehouse card already holds in the requesting cards', function (string $kind) {
    $user = navUser($kind);

    // Working stock, IMS and the item register sit in the warehouse card for them, not here.
    expect(tabLabels($user, MenuRegistry::CHEMICAL))->toBe(lbl(['tab_shop', 'tab_cart', 'tab_my_requisitions', 'chemical_lookup']));
    expect(tabLabels($user, MenuRegistry::CONSUMABLE))->toBe(lbl(['tab_shop', 'tab_cart', 'tab_my_requisitions']));
})->with(['lab_manager', 'auditor']);

// --- warehouse card ----------------------------------------------------------------------------

test('the warehouse card gives a manager every old warehouse entry: approvals, dispensing, stock takes, disposals, adjustments, IMS, stock, register, locations, members, reports', function (string $kind) {
    // ITM-2610-062: the "move by paper requisition" tab (ims_transfer.tab) sits right after the IMS tab.
    expect(tabLabels(navUser($kind), MenuRegistry::OPS))->toBe([
        ...lbl(['tab_overview', 'tab_waiting', 'tab_to_issue', 'stock_takes', 'disposals', 'adjustments', 'tab_ims']),
        (string) __('ims_transfer.tab'),
        ...lbl(['tab_working_stock_ops', 'tab_items', 'locations', 'lab_members', 'reports']),
    ]);
})->with(['lab_manager', 'auditor']);

test('ITM-2610-062: only an ims.manage holder with a branch gets the "move by paper requisition" tab (ADMIN and SCIENTIST do not), and its pages light only that tab', function () {
    $label = (string) __('ims_transfer.tab');

    expect(tabLabels(navUser('auditor'), MenuRegistry::OPS))->toContain($label);
    expect(tabLabels(navUser('lab_manager'), MenuRegistry::OPS))->toContain($label);
    expect(tabLabels(navUser('admin'), MenuRegistry::OPS))->not->toContain($label);
    expect(tabLabels(navUser('scientist'), MenuRegistry::OPS))->not->toContain($label);
    expect(tabLabels(navUser('lab_manager', ['lab_id' => null]), MenuRegistry::OPS))->not->toContain($label);

    // On the transfer page exactly one tab is lit: the new one, not "IMS" (which used to match `ims.*`).
    $nav = layoutNav($this->actingAs(navUser('auditor'))->get(route('ims.transfers.create'))->assertOk()->getContent());
    $active = array_values(array_filter($nav['tabs'], fn (array $t) => $t['active']));
    expect($nav['title'])->toBe(__('nav.card_ops'))->and($active)->toHaveCount(1)->and($active[0]['label'])->toBe($label);

    // And on the IMS stock list the IMS tab alone is lit.
    $nav = layoutNav($this->actingAs(navUser('auditor'))->get(route('ims.lots.index'))->assertOk()->getContent());
    $active = array_values(array_filter($nav['tabs'], fn (array $t) => $t['active']));
    expect($active)->toHaveCount(1)->and($active[0]['label'])->toBe(__('nav.tab_ims'));
});

test('approvals.md K: a SCIENTIST (approves and dispenses) gets the warehouse card with the overview, the approval queue and the dispensing queue, and none of the manager-only pages', function () {
    expect(tabLabels(navUser('scientist'), MenuRegistry::OPS))->toBe(lbl(['tab_overview', 'tab_waiting', 'tab_to_issue']));
});

test('ADMIN in the warehouse card gets read access to what ADMIN could already open, and none of the manager-only pages', function () {
    $labels = tabLabels(navUser('admin'), MenuRegistry::OPS);

    expect($labels)->toBe(lbl([
        'tab_overview', 'tab_waiting', 'tab_to_issue', 'stock_takes', 'disposals', 'adjustments',
        'tab_ims', 'tab_working_stock_ops',
    ]));
    // ADMIN holds none of these (PermissionSeeder): locations, members, the item register as manager, reports here.
    expect($labels)->not->toContain(__('nav.locations'))->not->toContain(__('nav.lab_members'))
        ->not->toContain(__('nav.reports'))->not->toContain(__('nav.tab_items'));
});

// --- back office -------------------------------------------------------------------------------

test('the back-office card is ADMIN\'s: users and roles, branches, the item register and every branch\'s reports', function () {
    expect(tabLabels(navUser('admin'), MenuRegistry::ADMIN))->toBe(lbl(['users_roles', 'labs', 'tab_items', 'reports']));

    foreach (['student', 'staff', 'advisor', 'scientist', 'lab_manager', 'auditor'] as $kind) {
        expect(app(MenuRegistry::class)->canAccessBackoffice(navUser($kind)))->toBeFalse("{$kind} must not reach the back office");
    }
});

test('reports have exactly one home per role: the warehouse card for managers, the back office for ADMIN', function () {
    $manager = navUser('auditor');
    $admin = navUser('admin');
    $registry = app(MenuRegistry::class);

    expect(tabLabels($manager, MenuRegistry::OPS))->toContain(__('nav.reports'));
    expect(tabLabels($manager, MenuRegistry::ADMIN))->toBe([]);

    expect(tabLabels($admin, MenuRegistry::OPS))->not->toContain(__('nav.reports'));
    expect(tabLabels($admin, MenuRegistry::ADMIN))->toContain(__('nav.reports'));
    expect($registry->canAccessBackoffice($admin))->toBeTrue();
});

// --- which section a page shows up in ---------------------------------------------------------

test('the layout puts a page in the right section with the right tab marked, once', function (string $kind, string $routeName, array $query, string $section, string $activeKey) {
    $user = navUser($kind);

    $nav = layoutNav($this->actingAs($user)->get(route($routeName, $query))->assertOk()->getContent());

    expect($nav['title'])->toBe(__('nav.card_'.$section));
    expect($nav['hasSidebar'])->toBeTrue();
    $active = array_values(array_filter($nav['tabs'], fn (array $t) => $t['active']));
    expect($active)->toHaveCount(1);
    expect($active[0]['label'])->toBe(__('nav.'.$activeKey));
})->with([
    'manager: stock takes' => ['auditor', 'stock-takes.index', [], 'ops', 'stock_takes'],
    'manager: disposals' => ['lab_manager', 'disposals.index', [], 'ops', 'disposals'],
    'manager: adjustments' => ['auditor', 'adjustments.index', [], 'ops', 'adjustments'],
    'manager: reports' => ['auditor', 'reports.index', [], 'ops', 'reports'],
    'manager: item register' => ['auditor', 'items.index', [], 'ops', 'tab_items'],
    'manager: locations' => ['auditor', 'locations.index', [], 'ops', 'locations'],
    'manager: branch members' => ['lab_manager', 'labs.members', [], 'ops', 'lab_members'],
    'manager: IMS' => ['auditor', 'ims.lots.index', [], 'ops', 'tab_ims'],
    'manager: working stock' => ['auditor', 'stock-in.index', [], 'ops', 'tab_working_stock_ops'],
    'manager: waiting for approval' => ['auditor', 'requisitions.manage', ['tab' => 'waiting'], 'ops', 'tab_waiting'],
    'manager: waiting to issue' => ['auditor', 'requisitions.manage', ['tab' => 'to_issue'], 'ops', 'tab_to_issue'],
    'manager: overview' => ['auditor', 'warehouse.overview', [], 'ops', 'tab_overview'],
    'dispenser: queue (?tab=to_issue)' => ['scientist', 'requisitions.manage', ['tab' => 'to_issue'], 'ops', 'tab_to_issue'],
    'ADMIN: stock takes stay in the warehouse card' => ['admin', 'stock-takes.index', [], 'ops', 'stock_takes'],
    'ADMIN: IMS' => ['admin', 'ims.lots.index', [], 'ops', 'tab_ims'],
    'ADMIN: users and roles' => ['admin', 'admin.users.index', [], 'adm', 'users_roles'],
    'ADMIN: branches' => ['admin', 'admin.labs.index', [], 'adm', 'labs'],
    'ADMIN: item register is back office' => ['admin', 'items.index', [], 'adm', 'tab_items'],
    'ADMIN: reports are back office' => ['admin', 'reports.index', [], 'adm', 'reports'],
    'requester: shop (chemicals)' => ['student', 'shop.index', ['type' => 'chemical'], 'chem', 'tab_shop'],
    'requester: shop (consumables)' => ['student', 'shop.index', ['type' => 'consumable'], 'supply', 'tab_shop'],
    'requester: cart (consumables)' => ['staff', 'shop.cart', ['type' => 'consumable'], 'supply', 'tab_cart'],
    'requester: my requisitions (chemicals)' => ['staff', 'requisitions.index', ['type' => 'chemical'], 'chem', 'tab_my_requisitions'],
    'requester: my requisitions (consumables)' => ['staff', 'requisitions.index', ['type' => 'consumable'], 'supply', 'tab_my_requisitions'],
    'requester: PubChem lookup' => ['student', 'chemicals.lookup', [], 'chem', 'chemical_lookup'],
    'requester: working stock' => ['student', 'stock-in.index', [], 'chem', 'tab_working_stock'],
    'requester: browse the branch IMS (read-only)' => ['student', 'ims.lots.index', [], 'chem', 'tab_ims_browse'],
    'requester: item register (read-only)' => ['student', 'items.index', [], 'chem', 'tab_items_view'],
]);

test('a page that is in no card (notifications, my data) has a sidebar with only the back button for multi-card users', function (string $routeName) {
    foreach (['student', 'auditor', 'admin'] as $kind) {
        $nav = layoutNav($this->actingAs(navUser($kind))->get(route($routeName))->assertOk()->getContent());

        expect($nav['hasSidebar'])->toBeTrue();
        expect($nav['tabs'])->toBe([]);
        expect($nav['back'])->toBe(route('dashboard'));
        // No card name heads the sidebar.
        expect($nav['title'])->toBe('');
    }
})->with(['notifications.index', 'account.my-data']);

test('tabsFor the back-office section is empty for anyone who fails access-backoffice, even if they hold one of its abilities', function () {
    $registry = app(MenuRegistry::class);

    foreach (['student', 'staff', 'advisor', 'scientist', 'lab_manager', 'auditor'] as $kind) {
        expect($registry->tabsFor(navUser($kind), MenuRegistry::ADMIN))->toBe([], "{$kind} must get no back-office tabs");
    }
    // ADMIN still gets them.
    expect($registry->tabsFor(navUser('admin'), MenuRegistry::ADMIN))->not->toBe([]);
});

test('the home page has no sidebar at all (so no back button) for a multi-card account', function () {
    $nav = layoutNav($this->actingAs(navUser('admin'))->get('/')->assertOk()->getContent());

    expect($nav['hasSidebar'])->toBeFalse();
    expect($nav['back'])->toBeNull();
});

test('a consumable item opens inside the consumable card and a chemical one inside the chemical card', function () {
    $student = navUser('student');
    $chemical = makeItem(['name_th' => 'ZCHEMDETAIL', 'category_id' => categoryId('CHEMICAL')]);
    $supply = makeItem(['name_th' => 'ZSUPPLYDETAIL', 'category_id' => categoryId('GLASSWARE')]);

    expect(layoutNav($this->actingAs($student)->get(route('items.show', $chemical))->assertOk()->getContent())['title'])->toBe(__('nav.card_chem'));
    expect(layoutNav($this->actingAs($student)->get(route('items.show', $supply))->assertOk()->getContent())['title'])->toBe(__('nav.card_supply'));
});

test('the requester\'s own requisition opens in the card of its real lines (not the box ticked), and a mixed one in the chemical card', function () {
    $student = navUser('student');

    $chem = requisitionWithLines($student, ['CHEMICAL'], ['request_type' => ['CHEMICAL']]);
    $supply = requisitionWithLines($student, ['MATERIAL'], ['request_type' => ['CONSUMABLE']]);
    $mixed = requisitionWithLines($student, ['CHEMICAL', 'MATERIAL'], ['request_type' => ['CHEMICAL', 'CONSUMABLE']]);
    // Ticked CHEMICAL (makeRequisition's default) but every line is a consumable: the lines decide.
    $tickedWrong = requisitionWithLines($student, ['GLASSWARE'], ['request_type' => ['CHEMICAL']]);

    $title = fn ($r) => layoutNav($this->actingAs($student)->get(route('requisitions.show', $r))->assertOk()->getContent())['title'];

    expect($title($chem))->toBe(__('nav.card_chem'));
    expect($title($supply))->toBe(__('nav.card_supply'));
    expect($title($mixed))->toBe(__('nav.card_chem'));
    expect($title($tickedWrong))->toBe(__('nav.card_supply'));
});

test('B3: on the manage queue only ?tab=waiting / ?tab=to_issue mark a tab; no ?tab= or ?tab=all marks none', function () {
    $manager = navUser('auditor');
    $active = fn (array $query) => array_values(array_map(
        fn ($t) => $t['label'],
        array_filter(layoutNav($this->actingAs($manager)->get(route('requisitions.manage', $query))->assertOk()->getContent())['tabs'], fn ($t) => $t['active']),
    ));

    expect($active([]))->toBe([]);
    expect($active(['tab' => 'all']))->toBe([]);
    expect($active(['tab' => 'done']))->toBe([]);
    expect($active(['tab' => 'waiting']))->toBe([__('nav.tab_waiting')]);
    expect($active(['tab' => 'to_issue']))->toBe([__('nav.tab_to_issue')]);

    // A scientist (approver and dispenser, no view_all) gets the "waiting" tab too (approvals.md K), and the same
    // rule applies to its queue: no ?tab= marks nothing.
    $dispenser = navUser('scientist');
    $tabs = layoutNav($this->actingAs($dispenser)->get(route('requisitions.manage'))->assertOk()->getContent())['tabs'];
    expect(array_filter($tabs, fn ($t) => $t['active']))->toBe([]);
});

test('B1: a manager opening someone else\'s requisition stays in the warehouse card; their own opens in a requesting card', function () {
    $lab = makeLab();
    $manager = auditorUser(['lab_id' => $lab->id, 'profile_completed_at' => now()]);
    $student = studentUser(['lab_id' => $lab->id]);

    $others = requisitionWithLines($student, ['MATERIAL'], ['lab_id' => $lab->id, 'status' => 'ADVISOR_APPROVED']);
    $own = requisitionWithLines($manager, ['MATERIAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    $nav = layoutNav($this->actingAs($manager)->get(route('requisitions.show', $others))->assertOk()->getContent());
    expect($nav['title'])->toBe(__('nav.card_ops'));
    $marked = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
    expect($marked)->toHaveCount(1);
    expect($marked[0]['label'])->toBe(__('nav.tab_waiting'));

    $mine = layoutNav($this->actingAs($manager)->get(route('requisitions.show', $own))->assertOk()->getContent());
    expect($mine['title'])->toBe(__('nav.card_supply'));
});

test('B1 (approvals.md K): a requisition opened by someone who dispenses is marked "to issue" once approved/issued, "waiting" while SUBMITTED; people who cannot dispense keep "waiting"', function () {
    $lab = makeLab();
    $student = studentUser(['lab_id' => $lab->id]);
    $tabOf = function ($user, string $url) {
        $nav = layoutNav($this->actingAs($user)->get($url)->assertOk()->getContent());
        expect($nav['title'])->toBe(__('nav.card_ops'), $url);
        $marked = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
        expect($marked)->toHaveCount(1);

        return $marked[0]['label'];
    };

    foreach (['scientistUser', 'auditorUser', 'labManagerUser'] as $factory) {
        $dispenser = $factory(['lab_id' => $lab->id, 'profile_completed_at' => now()]);

        foreach (['APPROVED', 'PARTIALLY_ISSUED', 'ISSUED'] as $status) {
            $req = requisitionWithLines($student, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => $status]);
            expect($tabOf($dispenser, route('requisitions.show', $req)))->toBe(__('nav.tab_to_issue'), "{$factory} {$status}");
        }

        $submitted = requisitionWithLines(staffUser(['lab_id' => $lab->id]), ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);
        expect($tabOf($dispenser, route('requisitions.show', $submitted)))->toBe(__('nav.tab_waiting'), "{$factory} SUBMITTED");
    }

    // ADMIN cannot dispense: an approved requisition stays under "waiting" for them.
    $approved = requisitionWithLines($student, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'APPROVED']);
    expect($tabOf(adminUser(['lab_id' => $lab->id, 'profile_completed_at' => now()]), route('requisitions.show', $approved)))->toBe(__('nav.tab_waiting'));

    // The issue page itself always marks "to issue".
    $scientist = scientistUser(['lab_id' => $lab->id, 'profile_completed_at' => now()]);
    expect($tabOf($scientist, route('requisitions.issue.create', $approved)))->toBe(__('nav.tab_to_issue'));
});

test('B4: the menu is remembered per user, so a role change needs a fresh MenuRegistry (and gets the right tabs with one)', function () {
    $user = navUser('staff');
    $before = array_column(app(MenuRegistry::class)->tabsFor($user, MenuRegistry::ADMIN), 'label');
    expect($before)->toBe([]);

    $user->roles()->attach(\App\Models\Role::where('code', 'ADMIN')->firstOrFail());
    $fresh = new MenuRegistry(app(\App\Domain\Reporting\Services\DashboardService::class));
    expect(array_column($fresh->tabsFor($user->fresh(), MenuRegistry::ADMIN), 'label'))->toContain(__('nav.users_roles'));
});
