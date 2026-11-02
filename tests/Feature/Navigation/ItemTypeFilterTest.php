<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 1 (plan 5.1 option B): `?type=chemical|consumable` narrows the shop and the
 * "my requisitions" list to one kind, read from the items' real categories (is_chemical), never
 * from the request_type box a requester ticked. Read-only filters: nothing is written, and no
 * permission changes. "consumable" is every non-chemical category (MATERIAL, CONSUMABLE, GLASSWARE).
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->shopper = staffUser(['lab_id' => $this->lab->id]);

    // One item per category flavour, each with stock in the shopper's branch so it is on the shelf.
    $this->shelfItem = function (string $name, string $categoryCode) {
        $item = makeItem(['name_th' => $name, 'category_id' => categoryId($categoryCode)]);
        makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '200.000000']);

        return $item;
    };
    ($this->shelfItem)('ZCHEMITEM', 'CHEMICAL');
    ($this->shelfItem)('ZDYEITEM', 'DYE');                 // another chemical category
    ($this->shelfItem)('ZSUPPLYITEM', 'CONSUMABLE');
    ($this->shelfItem)('ZMATERIALITEM', 'MATERIAL');
    ($this->shelfItem)('ZGLASSITEM', 'GLASSWARE');
});

test('shop ?type=chemical lists only chemical categories, ?type=consumable only the rest, and no type lists everything', function () {
    $chemical = $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'chemical']))->assertOk();
    $chemical->assertSee('ZCHEMITEM')->assertSee('ZDYEITEM')
        ->assertDontSee('ZSUPPLYITEM')->assertDontSee('ZMATERIALITEM')->assertDontSee('ZGLASSITEM');

    $consumable = $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'consumable']))->assertOk();
    $consumable->assertSee('ZSUPPLYITEM')->assertSee('ZMATERIALITEM')->assertSee('ZGLASSITEM')
        ->assertDontSee('ZCHEMITEM')->assertDontSee('ZDYEITEM');

    $all = $this->actingAs($this->shopper)->get(route('shop.index'))->assertOk();
    $all->assertSee('ZCHEMITEM')->assertSee('ZDYEITEM')->assertSee('ZSUPPLYITEM')->assertSee('ZMATERIALITEM')->assertSee('ZGLASSITEM');

    // An empty ?type= is "no filter", not an error.
    $this->actingAs($this->shopper)->get('/shop?type=')->assertOk()->assertSee('ZCHEMITEM')->assertSee('ZGLASSITEM');
});

test('shop ?type combines with search and with the category tab', function () {
    $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'consumable', 'search' => 'ZGLASS']))
        ->assertOk()->assertSee('ZGLASSITEM')->assertDontSee('ZSUPPLYITEM')->assertDontSee('ZCHEMITEM');

    // A search that only matches the other kind finds nothing under this type.
    $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'consumable', 'search' => 'ZCHEM']))
        ->assertOk()->assertDontSee('ZCHEMITEM');

    $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'chemical', 'category' => categoryId('DYE')]))
        ->assertOk()->assertSee('ZDYEITEM')->assertDontSee('ZCHEMITEM');
});

test('shop ?type= with anything but chemical or consumable is a validation error, not silently ignored', function (string $bad) {
    $this->actingAs($this->shopper)->get('/shop?type='.urlencode($bad))->assertSessionHasErrors('type');
})->with([
    'unknown word' => ['bogus'],
    'wrong case' => ['CHEMICAL'],
    'SET value instead of slug' => ['CONSUMABLE,CHEMICAL'],
    'injection-looking' => ["chemical' OR 1=1 --"],
]);

test('the shop filter changes what is listed, never what a user may do: a guest and a user who cannot request are still turned away', function () {
    $this->get(route('shop.index', ['type' => 'chemical']))->assertRedirect(route('login'));

    $cannotRequest = userWithPermissions(['audit.view'], ['lab_id' => $this->lab->id, 'profile_completed_at' => now()]);
    $this->actingAs($cannotRequest)->get(route('shop.index', ['type' => 'chemical']))->assertForbidden();
});

test('"my requisitions" ?type= filters by the lines\' real categories, a mixed requisition shows under both, no type shows all', function () {
    $user = $this->shopper;

    // `request_type` is left at its default CHEMICAL on all of them on purpose: only the lines decide.
    requisitionWithLines($user, ['CHEMICAL'], ['doc_no' => 'REQ-9001-00001']);
    requisitionWithLines($user, ['DYE', 'CHEMICAL'], ['doc_no' => 'REQ-9001-00002']);
    requisitionWithLines($user, ['GLASSWARE'], ['doc_no' => 'REQ-9001-00003']);               // declared CHEMICAL, lines consumable
    requisitionWithLines($user, ['MATERIAL', 'CONSUMABLE'], ['doc_no' => 'REQ-9001-00004']);
    requisitionWithLines($user, ['CHEMICAL', 'GLASSWARE'], ['doc_no' => 'REQ-9001-00005']);   // mixed
    makeRequisition($user, ['doc_no' => 'REQ-9001-00006']);                                   // no lines at all

    $chem = $this->actingAs($user)->get(route('requisitions.index', ['type' => 'chemical']))->assertOk();
    $chem->assertSee('REQ-9001-00001')->assertSee('REQ-9001-00002')->assertSee('REQ-9001-00005')
        ->assertDontSee('REQ-9001-00003')->assertDontSee('REQ-9001-00004')->assertDontSee('REQ-9001-00006');

    $supply = $this->actingAs($user)->get(route('requisitions.index', ['type' => 'consumable']))->assertOk();
    $supply->assertSee('REQ-9001-00003')->assertSee('REQ-9001-00004')->assertSee('REQ-9001-00005')
        ->assertDontSee('REQ-9001-00001')->assertDontSee('REQ-9001-00002')->assertDontSee('REQ-9001-00006');

    $all = $this->actingAs($user)->get(route('requisitions.index'))->assertOk();
    foreach (range(1, 6) as $n) {
        $all->assertSee('REQ-9001-0000'.$n);
    }
});

test('"my requisitions" ?type= never widens the scope: other people\'s requisitions stay out, advisees stay in', function () {
    $student = studentUser();
    $advisor = \App\Models\User::findOrFail($student->advisor_id);
    $stranger = studentUser();

    requisitionWithLines($student, ['CHEMICAL'], ['doc_no' => 'REQ-9002-00001']);       // advisor sees it as advisor
    requisitionWithLines($stranger, ['CHEMICAL'], ['doc_no' => 'REQ-9002-00002']);      // nobody's business
    requisitionWithLines($advisor, ['CHEMICAL'], ['doc_no' => 'REQ-9002-00003']);       // the advisor's own

    $this->actingAs($advisor)->get(route('requisitions.index', ['type' => 'chemical']))->assertOk()
        ->assertSee('REQ-9002-00001')->assertSee('REQ-9002-00003')->assertDontSee('REQ-9002-00002');

    $this->actingAs($advisor)->get(route('requisitions.index', ['type' => 'consumable']))->assertOk()
        ->assertDontSee('REQ-9002-00001')->assertDontSee('REQ-9002-00003')->assertDontSee('REQ-9002-00002');
});

test('"my requisitions" ?type= with an unknown value does not break the page and does not narrow the list', function () {
    // Current behaviour (the Livewire table ignores a value it does not know): the page opens and shows everything.
    // Contrast with the shop, which rejects it with a validation error. Both are safe (no SQL, no leak).
    requisitionWithLines($this->shopper, ['CHEMICAL'], ['doc_no' => 'REQ-9003-00001']);
    requisitionWithLines($this->shopper, ['GLASSWARE'], ['doc_no' => 'REQ-9003-00002']);

    $this->actingAs($this->shopper)->get(route('requisitions.index', ['type' => "x' OR '1'='1"]))->assertOk()
        ->assertSee('REQ-9003-00001')->assertSee('REQ-9003-00002');
});

test('a requisition that fits no card line-wise still opens and is found with no filter (legacy rows stay reachable)', function () {
    $legacy = makeRequisition($this->shopper, ['doc_no' => 'REQ-9004-00001', 'request_type' => ['CHEMICAL', 'CONSUMABLE']]);

    $this->actingAs($this->shopper)->get(route('requisitions.show', $legacy))->assertOk();
    $this->actingAs($this->shopper)->get(route('requisitions.index'))->assertOk()->assertSee('REQ-9004-00001');
});

test('the chemical-lookup (PubChem) tab belongs to the chemical card only', function () {
    $chem = layoutNav($this->actingAs($this->shopper)->get(route('requisitions.index', ['type' => 'chemical']))->assertOk()->getContent());
    $supply = layoutNav($this->actingAs($this->shopper)->get(route('requisitions.index', ['type' => 'consumable']))->assertOk()->getContent());

    expect(array_column($chem['tabs'], 'label'))->toContain(__('nav.chemical_lookup'));
    expect(array_column($supply['tabs'], 'label'))->not->toContain(__('nav.chemical_lookup'));
    expect($chem['title'])->toBe(__('nav.card_chem'));
    expect($supply['title'])->toBe(__('nav.card_supply'));
});

test('the shop leaves the ledger and stock alone: browsing with a type filter writes nothing', function () {
    $before = [
        \App\Models\StockLedger::count(),
        \App\Models\Container::sum('remaining_qty_base'),
        \App\Models\Requisition::count(),
    ];

    $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'chemical']))->assertOk();
    $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'consumable']))->assertOk();
    $this->actingAs($this->shopper)->get(route('requisitions.index', ['type' => 'chemical']))->assertOk();

    expect([
        \App\Models\StockLedger::count(),
        \App\Models\Container::sum('remaining_qty_base'),
        \App\Models\Requisition::count(),
    ])->toEqual($before);
});
