<?php

use App\Models\Requisition;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 2: one requisition = one kind of stock. The kind is decided by the system
 * (the basket / the card the user came from, and each item's category), never by boxes the
 * requester ticks. `request_type` is still a SET column kept for history, but new requisitions
 * always get exactly one value. "consumable" = every non-chemical category (MATERIAL,
 * CONSUMABLE, GLASSWARE). Each kind has its own session basket: shop.cart.chemical /
 * shop.cart.consumable (the old `shop.cart` key is no longer read).
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->shopper = staffUser(['lab_id' => $this->lab->id]);
    $this->g = Unit::where('code', 'g')->firstOrFail();

    $this->stocked = function (string $name, string $categoryCode) {
        $item = makeItem(['name_th' => $name, 'category_id' => categoryId($categoryCode)]);
        makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '500.000000']);

        return $item;
    };
    $this->chem = ($this->stocked)('ZKINDCHEM', 'CHEMICAL');
    $this->dye = ($this->stocked)('ZKINDDYE', 'DYE');
    $this->supply = ($this->stocked)('ZKINDSUPPLY', 'MATERIAL');
    $this->glass = ($this->stocked)('ZKINDGLASS', 'GLASSWARE');

    $this->addToCart = fn ($item, string $qty = '10') => $this->actingAs($this->shopper)
        ->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => $qty, 'unit_id' => $this->g->id]);
    $this->checkout = fn (array $data) => $this->actingAs($this->shopper)
        ->post(route('shop.checkout'), $data + ['purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลอง']);
});

// --- baskets --------------------------------------------------------------------------------

test('a chemical and a consumable go into separate baskets, never mixed, whichever page they were added from', function () {
    ($this->addToCart)($this->chem);
    ($this->addToCart)($this->dye);
    ($this->addToCart)($this->supply);
    ($this->addToCart)($this->glass);

    expect(array_keys(session('shop.cart.chemical')))->toEqualCanonicalizing([$this->chem->ulid, $this->dye->ulid]);
    expect(array_keys(session('shop.cart.consumable')))->toEqualCanonicalizing([$this->supply->ulid, $this->glass->ulid]);
    // Session keys with dots are nested arrays: the old single basket was a flat ulid => qty list under
    // `shop.cart`, so "gone" means that parent holds only the two per-kind baskets, no item rows.
    expect(array_keys((array) session('shop.cart')))->toEqualCanonicalizing(['chemical', 'consumable']);
});

test('the basket page shows only the basket of the requested kind, and an unknown kind is a validation error', function () {
    ($this->addToCart)($this->chem);
    ($this->addToCart)($this->supply);
    // The add-to-basket flash ("added: <name>") is shown on the next page; consume it first so the
    // assertions below look at the basket itself, not at that one-off message.
    $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'chemical']))->assertOk();

    $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'chemical']))->assertOk()
        ->assertSee('ZKINDCHEM')->assertDontSee('ZKINDSUPPLY');
    $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'consumable']))->assertOk()
        ->assertSee('ZKINDSUPPLY')->assertDontSee('ZKINDCHEM');

    $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'bogus']))->assertSessionHasErrors('type');
});

test('updating or removing an item only touches the basket it is in', function () {
    ($this->addToCart)($this->chem);
    ($this->addToCart)($this->supply);

    $this->actingAs($this->shopper)->put(route('shop.cart.update', $this->supply->ulid), ['qty' => '25'])
        ->assertRedirect(route('shop.cart', ['type' => 'consumable']));
    expect((float) session('shop.cart.consumable')[$this->supply->ulid]['qty'])->toEqual(25.0);
    expect((float) session('shop.cart.chemical')[$this->chem->ulid]['qty'])->toEqual(10.0);

    $this->actingAs($this->shopper)->delete(route('shop.cart.remove', $this->supply->ulid))
        ->assertRedirect(route('shop.cart', ['type' => 'consumable']));
    expect(session('shop.cart.consumable'))->toBe([]);
    expect(array_keys(session('shop.cart.chemical')))->toBe([$this->chem->ulid]);
});

// --- checkout -------------------------------------------------------------------------------

test('checking out the chemical basket makes one CHEMICAL requisition and leaves the consumable basket alone', function () {
    ($this->addToCart)($this->chem);
    ($this->addToCart)($this->dye);
    ($this->addToCart)($this->supply);

    ($this->checkout)(['type' => 'chemical'])->assertRedirect();

    $requisition = Requisition::firstOrFail();
    expect(Requisition::count())->toBe(1);
    expect($requisition->request_type)->toBe(['CHEMICAL']);
    expect($requisition->status)->toBe('SUBMITTED');
    expect($requisition->items()->pluck('item_id')->all())->toEqualCanonicalizing([$this->chem->id, $this->dye->id]);

    expect(session('shop.cart.chemical'))->toBeNull();
    expect(array_keys(session('shop.cart.consumable')))->toBe([$this->supply->ulid]);

    // Then the other basket, as its own requisition.
    ($this->checkout)(['type' => 'consumable'])->assertRedirect();

    expect(Requisition::count())->toBe(2);
    $second = Requisition::where('id', '!=', $requisition->id)->firstOrFail();
    expect($second->request_type)->toBe(['CONSUMABLE']);
    expect($second->items()->pluck('item_id')->all())->toBe([$this->supply->id]);
    expect(session('shop.cart.consumable'))->toBeNull();
});

test('the requisition kind comes from the basket: a posted request_type is ignored, and a missing, unknown or old-style value is an error', function () {
    ($this->addToCart)($this->supply);

    // A tampered request_type cannot change the stored kind.
    ($this->checkout)(['type' => 'consumable', 'request_type' => ['CHEMICAL', 'CONSUMABLE']])->assertRedirect();
    expect(Requisition::firstOrFail()->request_type)->toBe(['CONSUMABLE']);
});

test('a missing, unknown or old-style kind at checkout is an error and the basket is untouched', function () {
    ($this->addToCart)($this->supply);

    foreach ([[], ['type' => 'bogus'], ['type' => 'CHEMICAL'], ['request_type' => ['CHEMICAL']]] as $bad) {
        ($this->checkout)($bad)->assertSessionHasErrors('type');
    }
    expect(Requisition::count())->toBe(0);
    expect(array_keys(session('shop.cart.consumable')))->toBe([$this->supply->ulid]);   // basket untouched
});

test('checking out an empty basket of a kind is refused even when the other basket is full', function () {
    ($this->addToCart)($this->chem);

    ($this->checkout)(['type' => 'consumable'])->assertSessionHasErrors('cart');

    expect(Requisition::count())->toBe(0);
    expect(array_keys(session('shop.cart.chemical')))->toBe([$this->chem->ulid]);
});

test('an item of the wrong kind planted straight into a basket (session tampering or a category changed later) is refused at checkout', function () {
    $entry = fn ($item) => [$item->ulid => ['qty' => '10', 'unit_id' => $this->g->id]];

    // A consumable in the chemical basket.
    $this->withSession(['shop.cart.chemical' => $entry($this->supply)]);
    ($this->checkout)(['type' => 'chemical'])->assertSessionHasErrors('cart');
    expect(Requisition::count())->toBe(0);

    // A mixed basket: one good line must not carry the bad one through.
    $this->flushSession();
    $this->withSession(['shop.cart.consumable' => $entry($this->supply) + $entry($this->chem)]);
    ($this->checkout)(['type' => 'consumable'])->assertSessionHasErrors('cart');
    expect(Requisition::count())->toBe(0);

    // Category changed after the item was put in the basket.
    $this->flushSession();
    ($this->addToCart)($this->supply);
    $this->supply->update(['category_id' => categoryId('CHEMICAL')]);
    ($this->checkout)(['type' => 'consumable'])->assertSessionHasErrors('cart');
    expect(Requisition::count())->toBe(0);
});

// --- hand-made requisition form -------------------------------------------------------------

test('requisitions.store needs exactly one kind, and stores a single request_type value', function () {
    foreach ([[], ['type' => 'bogus'], ['request_type' => ['CHEMICAL']], ['request_type' => ['CHEMICAL', 'CONSUMABLE']]] as $bad) {
        $this->actingAs($this->shopper)->post(route('requisitions.store'), $bad + ['purpose_type' => 'TEACHING'])
            ->assertSessionHasErrors('type');
    }
    expect(Requisition::count())->toBe(0);

    $this->actingAs($this->shopper)->post(route('requisitions.store'), ['type' => 'consumable', 'request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING'])
        ->assertRedirect();
    expect(Requisition::firstOrFail()->request_type)->toBe(['CONSUMABLE']);
});

test('requisitions.create shows the kind it was opened with as a fixed value, and asks for exactly one (radio) when none is given', function () {
    foreach (['consumable', 'chemical'] as $kind) {
        $xp = htmlXPath($this->actingAs($this->shopper)->get(route('requisitions.create', ['type' => $kind]))->assertOk()->getContent());

        expect($xp->query('//form//input[@type="hidden" and @name="type"]')->item(0)->getAttribute('value'))->toBe($kind);
        expect($xp->query('//form//input[@type="radio" and @name="type"]')->length)->toBe(0);
        expect($xp->query('//form//input[@type="checkbox"]')->length)->toBe(0);
    }

    $this->actingAs($this->shopper)->get(route('requisitions.create', ['type' => 'consumable']))->assertSee(__('requisitions.request_type_consumable'));

    foreach ([[], ['type' => 'bogus']] as $query) {
        $xp = htmlXPath($this->actingAs($this->shopper)->get(route('requisitions.create', $query))->assertOk()->getContent());

        expect($xp->query('//form//input[@type="radio" and @name="type"]')->length)->toBe(2);
        expect($xp->query('//form//input[@type="hidden" and @name="type"]')->length)->toBe(0);
        expect($xp->query('//form//input[@type="checkbox"]')->length)->toBe(0);
    }
});

// --- adding lines and submitting ------------------------------------------------------------

test('a line of the other kind cannot be added to a draft (direct POST), a line of its own kind can', function () {
    $chemDraft = makeRequisition($this->shopper, ['request_type' => ['CHEMICAL']]);
    $supplyDraft = makeRequisition($this->shopper, ['request_type' => ['CONSUMABLE']]);
    $add = fn ($requisition, $item) => $this->actingAs($this->shopper)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id, 'qty_requested' => '5', 'unit_id' => $this->g->id,
    ]);

    $add($chemDraft, $this->supply)->assertSessionHasErrors('line');
    $add($chemDraft, $this->glass)->assertSessionHasErrors('line');
    $add($supplyDraft, $this->chem)->assertSessionHasErrors('line');
    $add($supplyDraft, $this->dye)->assertSessionHasErrors('line');
    expect($chemDraft->items()->count())->toBe(0);
    expect($supplyDraft->items()->count())->toBe(0);

    $add($chemDraft, $this->dye)->assertSessionHasNoErrors();
    $add($supplyDraft, $this->glass)->assertSessionHasNoErrors();
    expect($chemDraft->items()->count())->toBe(1);
    expect($supplyDraft->items()->count())->toBe(1);
});

test('an old draft ticked as both kinds takes one kind only once it has lines', function () {
    $draft = makeRequisition($this->shopper, ['request_type' => ['CHEMICAL', 'CONSUMABLE']]);
    $add = fn ($item) => $this->actingAs($this->shopper)->post(route('requisitions.items.store', $draft), [
        'item_id' => $item->id, 'qty_requested' => '5', 'unit_id' => $this->g->id,
    ]);

    $add($this->chem)->assertSessionHasNoErrors();       // the first line sets the kind
    $add($this->supply)->assertSessionHasErrors('line'); // a second kind is refused
    $add($this->dye)->assertSessionHasNoErrors();        // same kind is fine
    expect($draft->items()->count())->toBe(2);
});

test('an old DRAFT ticked as both kinds that already holds mixed lines cannot be submitted, and stays a draft', function () {
    $draft = requisitionWithLines($this->shopper, ['CHEMICAL', 'MATERIAL'], ['request_type' => ['CHEMICAL', 'CONSUMABLE']]);

    $this->actingAs($this->shopper)->post(route('requisitions.submit', $draft))->assertSessionHasErrors('submit');

    expect($draft->fresh()->status)->toBe('DRAFT');
});

test('a DRAFT ticked with the wrong kind but holding one real kind of line submits, and its request_type is corrected to that single kind', function () {
    $draft = requisitionWithLines($this->shopper, ['GLASSWARE'], ['request_type' => ['CHEMICAL']]);

    $this->actingAs($this->shopper)->post(route('requisitions.submit', $draft))->assertSessionHasNoErrors();

    $draft->refresh();
    expect($draft->status)->toBe('SUBMITTED');
    expect($draft->request_type)->toBe(['CONSUMABLE']);
});

test('a DRAFT whose lines are really mixed is refused with the one-kind message and stays a draft, whatever it declares', function () {
    foreach ([['CHEMICAL'], ['CONSUMABLE'], ['CHEMICAL', 'CONSUMABLE']] as $declared) {
        $draft = requisitionWithLines($this->shopper, ['CHEMICAL', 'GLASSWARE'], ['request_type' => $declared]);

        $this->actingAs($this->shopper)->post(route('requisitions.submit', $draft))
            ->assertSessionHasErrors(['submit' => __('requisitions.validation.mixed_item_types')]);

        $draft->refresh();
        expect($draft->status)->toBe('DRAFT');
        expect($draft->request_type)->toBe($declared);   // a refused submit changes nothing
    }
});

test('a legacy both-ticked DRAFT with one real kind is corrected to that kind on submit', function () {
    $draft = requisitionWithLines($this->shopper, ['MATERIAL'], ['request_type' => ['CHEMICAL', 'CONSUMABLE']]);

    $this->actingAs($this->shopper)->post(route('requisitions.submit', $draft))->assertSessionHasNoErrors();

    expect($draft->fresh()->request_type)->toBe(['CONSUMABLE']);
});

test('an old DRAFT ticked as both kinds but holding one kind of line still submits (nothing is stranded)', function () {
    $draft = requisitionWithLines($this->shopper, ['CHEMICAL', 'DYE'], ['request_type' => ['CHEMICAL', 'CONSUMABLE']]);

    $this->actingAs($this->shopper)->post(route('requisitions.submit', $draft))->assertSessionHasNoErrors();

    expect($draft->fresh()->status)->toBe('SUBMITTED');
});

// --- legacy mixed requisitions stay readable ------------------------------------------------

test('a legacy mixed requisition still opens, still lists, and shows under both kind filters', function () {
    $legacy = requisitionWithLines($this->shopper, ['CHEMICAL', 'MATERIAL'], [
        'doc_no' => 'REQ-9100-00001', 'request_type' => ['CHEMICAL', 'CONSUMABLE'], 'status' => 'SUBMITTED',
    ]);

    $this->actingAs($this->shopper)->get(route('requisitions.show', $legacy))->assertOk();
    $this->actingAs($this->shopper)->get(route('requisitions.index'))->assertOk()->assertSee('REQ-9100-00001');
    $this->actingAs($this->shopper)->get(route('requisitions.index', ['type' => 'chemical']))->assertOk()->assertSee('REQ-9100-00001');
    $this->actingAs($this->shopper)->get(route('requisitions.index', ['type' => 'consumable']))->assertOk()->assertSee('REQ-9100-00001');

    // A manager of the branch can still see and decide it like before.
    $manager = auditorUser(['lab_id' => $this->lab->id, 'profile_completed_at' => now()]);
    $legacy->update(['lab_id' => $this->lab->id]);
    $this->actingAs($manager)->get(route('requisitions.show', $legacy))->assertOk();
});

test('editing an existing requisition does not need or change its kind', function () {
    $draft = makeRequisition($this->shopper, ['request_type' => ['CONSUMABLE']]);

    $this->actingAs($this->shopper)->put(route('requisitions.update', $draft), ['purpose_type' => 'RESEARCH', 'purpose_detail' => 'แก้ไข'])
        ->assertSessionHasNoErrors();

    $draft->refresh();
    expect($draft->purpose_type)->toBe('RESEARCH');
    expect($draft->request_type)->toBe(['CONSUMABLE']);
});
