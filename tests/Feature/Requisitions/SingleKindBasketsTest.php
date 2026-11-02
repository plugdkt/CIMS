<?php

use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-052 phase 2, second round (after QA): baskets after a category change, stale session
 * data from before the split, the create form's old input, the basket switcher, and the shop's
 * basket button. See SingleKindRequisitionTest for the core one-kind rules.
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
    $this->chem = ($this->stocked)('ZBASKCHEM', 'CHEMICAL');
    $this->dye = ($this->stocked)('ZBASKDYE', 'DYE');
    $this->supply = ($this->stocked)('ZBASKSUPPLY', 'MATERIAL');
    $this->glass = ($this->stocked)('ZBASKGLASS', 'GLASSWARE');

    $this->addToCart = fn ($item, string $qty = '10') => $this->actingAs($this->shopper)
        ->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => $qty, 'unit_id' => $this->g->id]);
});

test('an item whose category changed after it went into a basket can still be updated and removed there; removing twice is harmless; an item in no basket is a 404 on update', function () {
    ($this->addToCart)($this->supply);
    $this->supply->update(['category_id' => categoryId('CHEMICAL')]);   // now a chemical, but it sits in the consumable basket

    $this->actingAs($this->shopper)->put(route('shop.cart.update', $this->supply->ulid), ['qty' => '20'])
        ->assertRedirect(route('shop.cart', ['type' => 'consumable']));
    expect((float) session('shop.cart.consumable')[$this->supply->ulid]['qty'])->toEqual(20.0);

    $this->actingAs($this->shopper)->delete(route('shop.cart.remove', $this->supply->ulid))
        ->assertRedirect(route('shop.cart', ['type' => 'consumable']));
    expect(session('shop.cart.consumable'))->toBe([]);

    // Second remove: nothing left to remove, and no error.
    $this->actingAs($this->shopper)->delete(route('shop.cart.remove', $this->supply->ulid))->assertRedirect()->assertSessionHasNoErrors();
    expect(session('shop.cart.consumable'))->toBe([]);

    // Update of something that is in no basket.
    $this->actingAs($this->shopper)->put(route('shop.cart.update', $this->glass->ulid), ['qty' => '5'])->assertNotFound();
});

test('the old flat `shop.cart` session value (ulid => row) leaks into nothing: not the badges, not the cart page, not the shop button', function () {
    $this->withSession(['shop.cart' => [
        $this->chem->ulid => ['qty' => '10', 'unit_id' => $this->g->id],
        $this->supply->ulid => ['qty' => '10', 'unit_id' => $this->g->id],
    ]]);

    // Home badges: only the "my open requisitions" badge on each requesting card, no cart badge.
    $cards = collect(homeCards($this->actingAs($this->shopper)->get('/')->assertOk()->getContent()))->keyBy('title');
    expect($cards[__('nav.card_chem')]['badges'])->toBe([__('nav.badge_my_open', ['count' => 0])]);
    expect($cards[__('nav.card_supply')]['badges'])->toBe([__('nav.badge_my_open', ['count' => 0])]);

    // The cart page of either kind is empty and the switcher shows 0 for both.
    foreach (['chemical', 'consumable'] as $kind) {
        $response = $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => $kind]))->assertOk();
        $response->assertDontSee('ZBASKCHEM')->assertDontSee('ZBASKSUPPLY')->assertSee(__('shop.cart_empty'));
        expect($response->viewData('baskets'))->toBe(['chemical' => 0, 'consumable' => 0]);
    }

    // The shop button shows no count, and a new basket starts clean next to the stale value.
    $this->actingAs($this->shopper)->get(route('shop.index'))->assertOk()->assertViewHas('cartCount', 0);
    ($this->addToCart)($this->chem);
    expect(array_keys(session('shop.cart.chemical')))->toBe([$this->chem->ulid]);
    expect(session('shop.cart.consumable'))->toBeNull();
});

test('requisitions.create with a bad old("type") never shows the raw value and falls back to the radio choice', function () {
    $response = $this->actingAs($this->shopper)->withSession(['_old_input' => ['type' => 'foo']])
        ->get(route('requisitions.create'))->assertOk();

    $response->assertDontSee('value="foo"', false);
    $xp = htmlXPath($response->getContent());
    expect($xp->query('//form//input[@type="radio" and @name="type"]')->length)->toBe(2);
    expect($xp->query('//form//input[@type="hidden" and @name="type"]')->length)->toBe(0);

    // A bad old value does not override the kind the form was opened with.
    $withQuery = htmlXPath($this->actingAs($this->shopper)->withSession(['_old_input' => ['type' => 'foo']])
        ->get(route('requisitions.create', ['type' => 'consumable']))->assertOk()->getContent());
    expect($withQuery->query('//form//input[@type="hidden" and @name="type"]')->item(0)->getAttribute('value'))->toBe('consumable');
});

test('the cart page links both baskets with their own counts and marks the current one', function () {
    ($this->addToCart)($this->chem);
    ($this->addToCart)($this->dye);
    ($this->addToCart)($this->supply);

    $xp = htmlXPath($this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'consumable']))->assertOk()->getContent());
    $links = $xp->query('//nav[@aria-label="'.__('shop.baskets_label').'"]//a');

    expect($links->length)->toBe(2);
    $byHref = [];
    foreach ($links as $a) {
        $byHref[$a->getAttribute('href')] = [
            'text' => trim((string) preg_replace('/\s+/u', ' ', $a->textContent)),
            'current' => $a->getAttribute('aria-current') === 'page',
        ];
    }

    $chemical = route('shop.cart', ['type' => 'chemical']);
    $consumable = route('shop.cart', ['type' => 'consumable']);
    expect($byHref[$chemical]['text'])->toContain('(2)');
    expect($byHref[$consumable]['text'])->toContain('(1)');
    expect($byHref[$chemical]['current'])->toBeFalse();
    expect($byHref[$consumable]['current'])->toBeTrue();
});

test('the cart page with no type opens the first basket that holds something, else the chemical one', function () {
    $this->actingAs($this->shopper)->get(route('shop.cart'))->assertOk()->assertViewHas('type', 'chemical');

    ($this->addToCart)($this->supply);
    $this->actingAs($this->shopper)->get(route('shop.cart'))->assertOk()->assertViewHas('type', 'consumable')->assertSee('ZBASKSUPPLY');

    ($this->addToCart)($this->chem);
    $this->actingAs($this->shopper)->get(route('shop.cart'))->assertOk()->assertViewHas('type', 'chemical');
});

test('the basket button on the shop page without a type leads to the first basket that holds something, else the chemical one', function () {
    $link = function () {
        $xp = htmlXPath($this->actingAs($this->shopper)->get(route('shop.index'))->assertOk()->getContent());

        return $xp->query('//main//a[contains(@href, "/shop/cart")]')->item(0)->getAttribute('href');
    };

    expect($link())->toBe(route('shop.cart', ['type' => 'chemical']));

    ($this->addToCart)($this->supply);
    expect($link())->toBe(route('shop.cart', ['type' => 'consumable']));

    ($this->addToCart)($this->chem);
    expect($link())->toBe(route('shop.cart', ['type' => 'chemical']));
});

test('adding a line of another kind than the draft declares is still refused, with the wrong-kind message', function () {
    $draft = makeRequisition($this->shopper, ['request_type' => ['CONSUMABLE']]);

    $this->actingAs($this->shopper)->post(route('requisitions.items.store', $draft), [
        'item_id' => $this->chem->id, 'qty_requested' => '5', 'unit_id' => $this->g->id,
    ])->assertSessionHasErrors(['line' => __('requisitions.validation.item_wrong_type')]);

    expect($draft->items()->count())->toBe(0);
});
