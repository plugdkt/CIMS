<?php

use App\Domain\Navigation\MenuRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1: "/" is no longer the analytics dashboard. It is a page of cards
 * (see tests/Feature/Navigation/*). The numbers, lists and charts that used to fill it live on
 * the warehouse card's overview tab (route warehouse.overview, GET /warehouse), with the same
 * scoping as before: a branch manager sees their own branch, and the analytics stay behind
 * report.view. Every case below keeps its original assertion, pointed at the new place.
 */

test('a guest sees the plain welcome page at /', function () {
    $this->get('/')->assertOk()->assertViewIs('welcome');
});

test('user-reported 2026-09-21 (reworked for ITM-2610-052): every page has a way back to the home page, from any page', function () {
    $scientist = navUser('scientist');

    $this->actingAs($scientist)->get(route('dashboard'))->assertOk()->assertViewIs('home');

    // A Blade page, a Livewire page, a page outside every card, and the warehouse overview.
    foreach (['items.index', 'requisitions.index', 'notifications.index', 'warehouse.overview'] as $name) {
        $nav = layoutNav($this->actingAs($scientist)->get(route($name))->assertOk()->getContent());

        expect($nav['back'])->toBe(route('dashboard'), "no back-to-home link on {$name}");
        expect($nav['logo'])->toBe(route('dashboard'), "logo does not lead home on {$name}");
    }
});

test('§7.9: a logged-in STUDENT sees the home cards (requesting only) and no report.view analytics anywhere', function () {
    $student = studentUser();

    $response = $this->actingAs($student)->get('/');

    $response->assertOk()->assertViewIs('home');
    $response->assertViewHas('cards');
    expect(array_column(homeCards($response->getContent()), 'title'))
        ->toBe([__('nav.card_chem'), __('nav.card_supply')]);
    $response->assertDontSee(__('home.below_reorder'));
    $response->assertDontSee(__('home.monthly_chart_title'));

    // The analytics moved to /warehouse; a STUDENT cannot open it by URL either (hiding the card is not the protection).
    $this->actingAs($student)->get(route('warehouse.overview'))->assertForbidden();
});

test('§7.9: a warehouse manager (report.view) sees the full analytics on the warehouse overview, not on the home page', function () {
    $manager = auditorUser();

    $response = $this->actingAs($manager)->get(route('warehouse.overview'));

    $response->assertOk()->assertViewIs('warehouse.overview');
    $response->assertViewHas('belowReorderCount');
    $response->assertViewHas('expiringCount');
    $response->assertViewHas('topItems');
    $response->assertViewHas('monthlySeries');
    $response->assertSee(__('home.below_reorder'));
    $response->assertSee(__('home.monthly_chart_title'));

    $this->actingAs($manager)->get('/')->assertOk()
        ->assertDontSee(__('home.below_reorder'))
        ->assertDontSee(__('home.monthly_chart_title'));
});

test('§7.9 (user-requested 2026-09-21): the warehouse overview names which items are actually low/expiring, not just a count', function () {
    $manager = auditorUser();
    $g = \App\Models\Unit::where('code', 'g')->firstOrFail();
    $ledgerUser = \App\Models\User::factory()->create();

    $lowItem = makeItem(['name_th' => 'สารใกล้หมดสำหรับทดสอบ', 'reorder_point_base' => '100.000000']);
    app(\App\Domain\Inventory\Services\LedgerService::class)->receive(
        makeContainer(['item_id' => $lowItem->id])->id,
        '5.000000',
        new \App\Domain\Inventory\DTO\LedgerEntryData(displayUnitId: $g->id, createdBy: $ledgerUser->id),
    );

    $expiringItem = makeItem(['name_th' => 'สารใกล้หมดอายุสำหรับทดสอบ']);
    makeContainer(['item_id' => $expiringItem->id, 'status' => 'IN_USE', 'expiry_date' => now()->addDays(5)->toDateString()]);

    $response = $this->actingAs($manager)->get(route('warehouse.overview'));

    $response->assertOk()
        ->assertSee('สารใกล้หมดสำหรับทดสอบ')
        ->assertSee('สารใกล้หมดอายุสำหรับทดสอบ');
});

test('a SCIENTIST (no report.view) sees only the queue numbers on the warehouse overview, never the analytics', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id]);

    $response = $this->actingAs($scientist)->get(route('warehouse.overview'));

    $response->assertOk();
    $response->assertViewMissing('belowReorderCount');
    $response->assertViewMissing('topItems');
    $response->assertDontSee(__('home.below_reorder'));
    $response->assertDontSee(__('home.monthly_chart_title'));
    // approvals.md K: a scientist decides requisitions of their own branch too (none waiting here)
    expect($response->viewData('approvalCount'))->toBe(0)
        ->and($response->viewData('dispenseCount'))->toBe(0);
});

test('dashboard (updated 2026-09-22, split by ITM-2610-052): a SCIENTIST\'s own requisitions and their branch\'s dispensing queue are now two separate numbers', function () {
    $lab = makeLab();
    $scientist = scientistUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $student = studentUser(['lab_id' => $lab->id]);

    // Awaiting issuance in their own branch — genuinely this scientist's dispensing work.
    makeRequisition($student, ['lab_id' => $lab->id, 'status' => 'APPROVED']);

    // Their own in-flight requisition (has a line, so it belongs to the chemical card).
    requisitionWithLines($scientist, ['CHEMICAL'], ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    // Another branch's, awaiting issuance — not theirs to dispense.
    makeRequisition(studentUser(), ['lab_id' => makeLab()->id, 'status' => 'APPROVED']);

    // A student's, still waiting for the advisor — not in anyone's approval queue yet.
    makeRequisition(studentUser(), ['lab_id' => $lab->id, 'status' => 'SUBMITTED']);

    // Already finished — nothing left to do.
    makeRequisition(studentUser(), ['lab_id' => $lab->id, 'status' => 'ISSUED']);

    $overview = $this->actingAs($scientist)->get(route('warehouse.overview'));
    expect($overview->assertOk()->viewData('dispenseCount'))->toBe(1);

    $cards = collect(homeCards($this->actingAs($scientist)->get('/')->assertOk()->getContent()))->keyBy('title');
    // approvals.md K: the scientist's own non-student SUBMITTED requisition is in their branch's approval queue
    // (approving one's own is allowed, and audited) next to the one APPROVED requisition waiting to be dispensed.
    expect($cards[__('nav.card_ops')]['badges'])->toBe([__('nav.badge_approval', ['count' => 1]), __('nav.badge_dispense', ['count' => 1])])
        ->and($cards[__('nav.card_chem')]['badges'])->toBe([__('nav.badge_my_open', ['count' => 1])])
        ->and($cards[__('nav.card_supply')]['badges'])->toBe([__('nav.badge_my_open', ['count' => 0])]);
});

test('dashboard: a warehouse manager (AUDITOR) only sees pending requisitions in their own branch', function () {
    // approvals.md K: the manager also dispenses, so the card carries a (zero here) dispense badge as well.
    $labA = makeLab();
    $labB = makeLab();
    $auditor = auditorUser(['lab_id' => $labA->id]);
    $studentA = studentUser(['lab_id' => $labA->id]);
    $studentB = studentUser(['lab_id' => $labB->id]);

    makeRequisition($studentA, ['lab_id' => $labA->id, 'status' => 'ADVISOR_APPROVED']);
    makeRequisition($studentB, ['lab_id' => $labB->id, 'status' => 'ADVISOR_APPROVED']);

    $overview = $this->actingAs($auditor)->get(route('warehouse.overview'));
    expect($overview->assertOk()->viewData('approvalCount'))->toBe(1);

    $cards = collect(homeCards($this->actingAs($auditor)->get('/')->assertOk()->getContent()))->keyBy('title');
    expect($cards[__('nav.card_ops')]['badges'])->toBe([managedBadge(['chemical', 'consumable']), __('nav.badge_approval', ['count' => 1]), __('nav.badge_dispense', ['count' => 0])]);
});

test('dashboard: a warehouse manager (AUDITOR) only sees expiring containers from their own branch', function () {
    $labA = makeLab();
    $labB = makeLab();
    $locA = makeLocationForLab($labA);
    $locB = makeLocationForLab($labB);
    $auditor = auditorUser(['lab_id' => $labA->id]);

    $item = makeItem();
    makeContainer([
        'item_id' => $item->id,
        'location_id' => $locA->id,
        'status' => 'IN_USE',
        'expiry_date' => now()->addDays(5)->toDateString(),
    ]);
    makeContainer([
        'item_id' => $item->id,
        'location_id' => $locB->id,
        'status' => 'IN_USE',
        'expiry_date' => now()->addDays(5)->toDateString(),
    ]);

    $response = $this->actingAs($auditor)->get(route('warehouse.overview'));
    $response->assertOk()->assertViewHas('expiringCount', 1);
});

test('the warehouse overview is read-only for ADMIN: no write link on the page and no other verb on the route', function () {
    $admin = adminUser();

    $this->actingAs($admin)->get(route('warehouse.overview'))
        ->assertOk()
        ->assertDontSee(route('adjustments.create'), false);

    $this->actingAs($admin)->post(route('warehouse.overview'))->assertStatus(405);
    $this->actingAs($admin)->put(route('warehouse.overview'))->assertStatus(405);
    $this->actingAs($admin)->delete(route('warehouse.overview'))->assertStatus(405);

    // MenuRegistry is the single source for both the home cards and the tab bar; ADMIN reaches
    // the warehouse side through it only by the abilities ADMIN already held.
    expect(app(MenuRegistry::class)->canAccessWarehouse($admin))->toBeTrue();
});

test('a guest is sent to login from the warehouse overview', function () {
    $this->get(route('warehouse.overview'))->assertRedirect(route('login'));
});
