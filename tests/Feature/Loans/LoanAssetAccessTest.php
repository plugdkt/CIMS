<?php

use App\Domain\Navigation\MenuRegistry;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use App\Models\Permission;
use App\Policies\LoanAssetPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 1: who reaches the lending register and the "available to lend" page, branch by branch,
 * through the REAL routes. The branch check is the point of these tests: AppServiceProvider's Gate::before
 * returns true when an ability NAME equals a permission code the user holds, so a policy method called
 * like `loan.asset_manage` would silently skip every branch check. The first two tests pin that down.
 */

test('no LoanAssetPolicy method is named like a permission code (Gate::before would then skip the branch checks)', function () {
    $codes = Permission::pluck('code')->all();
    $methods = array_map('strtolower', get_class_methods(LoanAssetPolicy::class));

    foreach ($codes as $code) {
        expect($methods)->not->toContain(strtolower($code));
        expect($methods)->not->toContain(strtolower(str_replace('.', '_', $code)));
    }
});

test('a holder of loan.asset_manage in branch B gets 403 on every route of an asset of branch A, and nothing changes', function () {
    [$labA, $managerA, $colleagueA] = loanManagers();
    [$labB, $managerB] = loanManagers();
    $asset = registerLoanAsset($managerA, $labA, 'POOL', 10);
    $note = 'เหตุผลที่ยาวพอสำหรับทดสอบ';

    // Same branch: reachable.
    $this->actingAs($managerA)->get(route('loans.assets.show', $asset))->assertOk();

    // Other branch, same permission: refused everywhere.
    $this->actingAs($managerB)->get(route('loans.assets.show', $asset))->assertForbidden();
    $this->actingAs($managerB)->put(route('loans.assets.update', $asset), ['max_loan_days' => 3, 'condition_note' => 'แก้โดยสาขาอื่น'])->assertForbidden();
    $this->actingAs($managerB)->post(route('loans.assets.adjust', $asset), ['direction' => 'IN', 'qty' => 1, 'note' => $note, 'approved_by' => $colleagueA->id])->assertForbidden();
    $this->actingAs($managerB)->post(route('loans.assets.retire', $asset), ['note' => $note, 'approved_by' => $colleagueA->id])->assertForbidden();
    $this->actingAs($managerB)->post(route('loans.assets.verify', $asset))->assertForbidden();

    $fresh = $asset->fresh();
    expect($fresh->qty_total)->toBe('10.000000')->and($fresh->status)->toBe('ACTIVE')->and($fresh->max_loan_days)->toBeNull()->and($fresh->condition_note)->toBeNull();
    expect(LoanMovement::where('loan_asset_id', $asset->id)->count())->toBe(1);
});

test('the registry list shows a manager only their own branch; ADMIN sees every branch and can narrow to one', function () {
    [$labA, $managerA] = loanManagers();
    [$labB, $managerB] = loanManagers();
    $itemA = makeCountItem(['name_th' => 'ของสาขาเอ ZLOANA']);
    $itemB = makeCountItem(['name_th' => 'ของสาขาบี ZLOANB']);
    registerLoanAsset($managerA, $labA, 'POOL', 3, $itemA);
    registerLoanAsset($managerB, $labB, 'POOL', 3, $itemB);

    $this->actingAs($managerA)->get(route('loans.assets.index'))->assertOk()->assertSee('ZLOANA')->assertDontSee('ZLOANB');
    $this->actingAs($managerB)->get(route('loans.assets.index'))->assertOk()->assertSee('ZLOANB')->assertDontSee('ZLOANA');

    $admin = adminUser();   // an ADMIN is not narrowed to a branch, even with one on the profile
    $this->actingAs($admin)->get(route('loans.assets.index'))->assertOk()->assertSee('ZLOANA')->assertSee('ZLOANB');
    $this->actingAs(adminUser(['lab_id' => $labA->id]))->get(route('loans.assets.index'))->assertOk()->assertSee('ZLOANA')->assertSee('ZLOANB');
    $this->actingAs($admin)->get(route('loans.assets.index', ['lab_id' => $labB->id]))->assertOk()->assertSee('ZLOANB')->assertDontSee('ZLOANA');

    // A manager cannot widen the list by passing another branch.
    $this->actingAs($managerA)->get(route('loans.assets.index', ['lab_id' => $labB->id]))->assertOk()->assertSee('ZLOANA')->assertDontSee('ZLOANB');

    $this->actingAs($admin)->get(route('loans.assets.show', LoanAsset::where('lab_id', $labA->id)->firstOrFail()))->assertOk();
    $this->actingAs($admin)->get(route('loans.assets.show', LoanAsset::where('lab_id', $labB->id)->firstOrFail()))->assertOk();
});

test('the "available" page is per branch too: own branch only for staff and managers, any branch for ADMIN, never a price', function () {
    [$labA, $managerA] = loanManagers();
    [$labB, $managerB] = loanManagers();
    $a = loanService()->register($managerA, ['lab_id' => $labA->id, 'item_id' => makeCountItem(['name_th' => 'ZAVAILA'])->id, 'tracking_mode' => 'POOL', 'qty' => 4, 'replacement_value' => '12345.50']);
    loanService()->register($managerB, ['lab_id' => $labB->id, 'item_id' => makeCountItem(['name_th' => 'ZAVAILB'])->id, 'tracking_mode' => 'POOL', 'qty' => 4]);
    $counter = scientistUser(['lab_id' => $labA->id]);

    $this->actingAs($counter)->get(route('loans.available'))->assertOk()->assertSee('ZAVAILA')->assertDontSee('ZAVAILB')->assertDontSee('12345');
    $this->actingAs($managerA)->get(route('loans.available'))->assertOk()->assertSee('ZAVAILA')->assertDontSee('ZAVAILB')->assertDontSee('12345');
    $this->actingAs(adminUser())->get(route('loans.available'))->assertOk()->assertSee('ZAVAILA')->assertSee('ZAVAILB')->assertDontSee('12345');

    // Retired assets are not "available".
    // (approvals.md K: a scientist of the branch approves the retirement; ADMIN can no longer stand in.)
    loanService()->retire($managerA, $a, 'ปลดระวางเพราะเสื่อมสภาพทั้งหมด', $counter->id);
    $this->actingAs($counter)->get(route('loans.available'))->assertOk()->assertDontSee('ZAVAILA');
});

test('the replacement value is shown only where an asset manager looks at an asset, never to anyone else', function () {
    [$lab, $manager] = loanManagers();
    $asset = loanService()->register($manager, ['lab_id' => $lab->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 2, 'replacement_value' => '12345.50']);

    $this->actingAs($manager)->get(route('loans.assets.show', $asset))->assertOk()->assertSee('12345.50');

    // Those without loan.asset_manage or loan.asset_approve cannot open the asset page at all
    // (a same-branch scientist holds loan.asset_approve and may read it, see LoanAssetApproverViewTest).
    foreach ([staffUser(['lab_id' => $lab->id]), studentUser(['lab_id' => $lab->id])] as $user) {
        $this->actingAs($user)->get(route('loans.assets.show', $asset))->assertForbidden();
    }
});

test('a manager with no branch, and everyone without loan.asset_manage, cannot enter the registry', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 3);
    $noBranch = auditorUser(['lab_id' => null]);

    $this->actingAs($noBranch)->get(route('loans.assets.index'))->assertForbidden();
    $this->actingAs($noBranch)->get(route('loans.assets.create'))->assertForbidden();
    $this->actingAs($noBranch)->get(route('loans.available'))->assertForbidden();
    $this->actingAs($noBranch)->get(route('loans.assets.show', $asset))->assertForbidden();
    $this->actingAs($noBranch)->post(route('loans.assets.store'), ['item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 1])->assertForbidden();

    foreach ([scientistUser(['lab_id' => $lab->id]), staffUser(['lab_id' => $lab->id]), studentUser(['lab_id' => $lab->id]), navUser('nocard')] as $user) {
        $this->actingAs($user)->get(route('loans.assets.index'))->assertForbidden();
        $this->actingAs($user)->get(route('loans.assets.create'))->assertForbidden();
        $this->actingAs($user)->post(route('loans.assets.store'), ['item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => 1])->assertForbidden();
    }
    expect(LoanAsset::count())->toBe(1);
});

test('registering through the form: a manager is bound to their own branch, ADMIN must choose one', function () {
    [$lab, $manager] = loanManagers();
    $otherLab = makeLab();
    $item = makeCountItem();

    // Own branch is implied.
    $this->actingAs($manager)->post(route('loans.assets.store'), ['item_id' => $item->id, 'tracking_mode' => 'POOL', 'qty' => 5])->assertRedirect();
    expect(LoanAsset::firstOrFail()->lab_id)->toBe($lab->id);

    // Another branch posted: refused, nothing created.
    $other = makeCountItem();
    $this->actingAs($manager)->post(route('loans.assets.store'), ['lab_id' => $otherLab->id, 'item_id' => $other->id, 'tracking_mode' => 'POOL', 'qty' => 5])->assertSessionHasErrors('lab_id');
    expect(LoanAsset::count())->toBe(1);

    // ADMIN: no branch chosen -> refused; chosen -> created there.
    $admin = adminUser();
    $this->actingAs($admin)->post(route('loans.assets.store'), ['item_id' => $other->id, 'tracking_mode' => 'POOL', 'qty' => 5])->assertSessionHasErrors('lab_id');
    expect(LoanAsset::count())->toBe(1);
    $this->actingAs($admin)->post(route('loans.assets.store'), ['lab_id' => $otherLab->id, 'item_id' => $other->id, 'tracking_mode' => 'POOL', 'qty' => 5])->assertRedirect();
    expect(LoanAsset::where('item_id', $other->id)->firstOrFail()->lab_id)->toBe($otherLab->id);
});

test('registering through the form: only whole quantities, only countable items, UNIT is exactly 1', function () {
    [$lab, $manager] = loanManagers();

    foreach (['3.5', '0', '-1', 'abc', ''] as $bad) {
        $this->actingAs($manager)->post(route('loans.assets.store'), ['item_id' => makeCountItem()->id, 'tracking_mode' => 'POOL', 'qty' => $bad])->assertSessionHasErrors('qty');
    }
    $this->actingAs($manager)->post(route('loans.assets.store'), ['item_id' => makeCountItem()->id, 'tracking_mode' => 'UNIT', 'qty' => 2])->assertSessionHasErrors('qty');

    // Mass and volume items are turned back with the BR-L01 message.
    foreach (['g', 'mL'] as $unit) {
        $this->actingAs($manager)->post(route('loans.assets.store'), ['item_id' => makeCountItem([], $unit)->id, 'tracking_mode' => 'POOL', 'qty' => 2])
            ->assertSessionHasErrors(['asset' => __('loans.error.item_not_countable')]);
    }
    expect(LoanAsset::count())->toBe(0);
});

test('adjust and retire through the form: a note, a different approver, whole quantity; the service rules show as form errors', function () {
    [$lab, $manager, $approver] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $note = 'เหตุผลที่ยาวพอสำหรับทดสอบ';

    $this->actingAs($manager)->post(route('loans.assets.adjust', $asset), ['direction' => 'IN', 'qty' => '1.5', 'note' => $note, 'approved_by' => $approver->id])->assertSessionHasErrors('qty');
    $this->actingAs($manager)->post(route('loans.assets.adjust', $asset), ['direction' => 'IN', 'qty' => 1, 'note' => 'สั้น', 'approved_by' => $approver->id])->assertSessionHasErrors('note');
    $this->actingAs($manager)->post(route('loans.assets.adjust', $asset), ['direction' => 'IN', 'qty' => 1, 'note' => $note, 'approved_by' => $manager->id])->assertSessionHasErrors(['adjust' => __('loans.error.approver_invalid')]);
    expect($asset->fresh()->qty_total)->toBe('10.000000');

    $this->actingAs($manager)->post(route('loans.assets.adjust', $asset), ['direction' => 'IN', 'qty' => 2, 'note' => $note, 'approved_by' => $approver->id])->assertRedirect(route('loans.assets.show', $asset));
    expect($asset->fresh()->qty_total)->toBe('12.000000');

    $this->actingAs($manager)->post(route('loans.assets.retire', $asset), ['note' => $note, 'approved_by' => $manager->id])->assertSessionHasErrors(['retire' => __('loans.error.approver_invalid')]);
    $this->actingAs($manager)->post(route('loans.assets.retire', $asset), ['note' => $note, 'approved_by' => $approver->id])->assertRedirect(route('loans.assets.show', $asset));
    expect($asset->fresh()->status)->toBe('RETIRED');
});

test('the edit form cannot change quantities or status, whatever is posted', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    $this->actingAs($manager)->put(route('loans.assets.update', $asset), [
        'max_loan_days' => 7, 'condition_note' => 'สภาพดี',
        'qty_total' => 999, 'qty_on_loan' => 5, 'qty_unavailable' => 2, 'status' => 'RETIRED', 'lab_id' => makeLab()->id, 'item_id' => makeCountItem()->id, 'tracking_mode' => 'UNIT',
    ])->assertRedirect(route('loans.assets.show', $asset));

    $fresh = $asset->fresh();
    expect($fresh->max_loan_days)->toBe(7)->and($fresh->condition_note)->toBe('สภาพดี');
    expect($fresh->qty_total)->toBe('10.000000')->and($fresh->qty_on_loan)->toBe('0.000000')->and($fresh->qty_unavailable)->toBe('0.000000');
    expect($fresh->status)->toBe('ACTIVE')->and($fresh->lab_id)->toBe($lab->id)->and($fresh->tracking_mode)->toBe('POOL');
});

test('the edit form accepts only a location of the asset\'s own branch', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);
    $own = makeLocationForLab($lab);
    $foreign = makeLocationForLab(makeLab());

    $this->actingAs($manager)->put(route('loans.assets.update', $asset), ['location_id' => $foreign->id])->assertSessionHasErrors('location_id');
    $this->actingAs($manager)->put(route('loans.assets.update', $asset), ['location_id' => $own->id])->assertRedirect();
    expect($asset->fresh()->location_id)->toBe($own->id);
});

test('verify from the page is for ADMIN (loan.verify) only, and reports an intact chain', function () {
    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 10);

    $this->actingAs($manager)->post(route('loans.assets.verify', $asset))->assertForbidden();
    $this->actingAs(adminUser())->post(route('loans.assets.verify', $asset))->assertRedirect(route('loans.assets.show', $asset))->assertSessionHas('status', __('loans.verify_ok'));
});

// --- the menu ------------------------------------------------------------------------------------------------

test('the loan card has exactly the tabs the user may open: dispenser = available; manager and ADMIN = available + register; others = none', function () {
    $registry = fn ($user) => array_column(app(MenuRegistry::class)->tabsFor($user, MenuRegistry::LOAN), 'label');

    expect($registry(navUser('scientist')))->toBe([__('nav.tab_loan_available')]);
    foreach (['lab_manager', 'auditor', 'admin'] as $kind) {
        expect($registry(navUser($kind)))->toBe([__('nav.tab_loan_available'), __('nav.tab_loan_registry')], $kind);
    }
    foreach (['student', 'staff', 'advisor', 'nocard'] as $kind) {
        expect($registry(navUser($kind)))->toBe([], $kind);
    }
    // No branch: a dispenser or manager without one has no tab (ADMIN needs none).
    expect($registry(navUser('scientist', ['lab_id' => null])))->toBe([]);
    expect($registry(navUser('auditor', ['lab_id' => null])))->toBe([]);
    expect($registry(navUser('admin', ['lab_id' => null])))->toBe([__('nav.tab_loan_available'), __('nav.tab_loan_registry')]);
});

test('the loan card shows only for users who have a tab in it, and each loan page opens inside it with its own tab marked', function () {
    $titles = fn ($user) => array_column(homeCards($this->actingAs($user)->get('/')->assertOk()->getContent()), 'title');

    expect($titles(navUser('student')))->not->toContain(__('nav.card_loan'));
    expect($titles(navUser('scientist', ['lab_id' => null])))->not->toContain(__('nav.card_loan'));
    expect($titles(navUser('scientist')))->toContain(__('nav.card_loan'));

    [$lab, $manager] = loanManagers();
    $asset = registerLoanAsset($manager, $lab, 'POOL', 3);
    foreach ([
        [route('loans.available'), 'tab_loan_available'],
        [route('loans.assets.index'), 'tab_loan_registry'],
        [route('loans.assets.create'), 'tab_loan_registry'],
        [route('loans.assets.show', $asset), 'tab_loan_registry'],
    ] as [$url, $activeKey]) {
        $nav = layoutNav($this->actingAs($manager)->get($url)->assertOk()->getContent());

        expect($nav['title'])->toBe(__('nav.card_loan'), $url);
        $active = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
        expect($active)->toHaveCount(1);
        expect($active[0]['label'])->toBe(__('nav.'.$activeKey), $url);
    }
});

// --- accessibility ---------------------------------------------------------------------------------------------

test('every loan page has a matching id for each label, and scrollable tables are keyboard-focusable', function () {
    [$lab, $manager, $approver] = loanManagers();
    $item = makeCountItem(['name_th' => 'ZA11YITEM']);
    $asset = registerLoanAsset($manager, $lab, 'POOL', 4, $item);

    $pages = [
        route('loans.available'),
        route('loans.assets.index'),
        route('loans.assets.create', ['q' => 'ZA11YITEM']),
        route('loans.assets.show', $asset),
    ];
    foreach ($pages as $url) {
        $html = $this->actingAs($manager)->get($url)->assertOk()->getContent();
        $xp = htmlXPath($html);

        foreach ($xp->query('//label[@for]') as $label) {
            $target = $label->getAttribute('for');
            expect($xp->query('//*[@id="'.$target.'"]')->length)->toBeGreaterThanOrEqual(1, "{$url}: label for={$target} has no target");
        }
        foreach ($xp->query('//*[contains(@class, "overflow-x-auto")]') as $scroller) {
            expect($scroller->hasAttribute('tabindex'))->toBeTrue("{$url}: scrollable region without tabindex");
        }
    }

    // The register form lists the countable item to pick (the picker is part of the label/id chain).
    $this->actingAs($manager)->get(route('loans.assets.create', ['q' => 'ZA11YITEM']))->assertSee('ZA11YITEM');
    expect($approver->id)->not->toBeNull();
});
