<?php

use App\Domain\Requisition\Services\ShopAvailability;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * ITM-2610-062: users may SEE what the central store (IMS) holds, but only WORKING stock can be requested.
 * `ShopAvailability::orderable` (= working) is the number every "may this be requested" decision uses.
 * Stuff that exists only in IMS shows on the shelf with a badge and a "ask for a refill" button instead of a cart button.
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->otherLocation = makeLocationForLab($this->otherLab);
    $this->shopper = staffUser(['lab_id' => $this->lab->id]);
    $this->g = Unit::where('code', 'g')->firstOrFail();
    $this->availability = app(ShopAvailability::class);

    // An item that sits ONLY in the branch's IMS store.
    $this->imsOnly = function (string $name = 'สารมีแต่ในคลังกลาง', array $overrides = []): Item {
        $item = $overrides['consumable'] ?? false ? consumableItem(['name_th' => $name]) : makeItem(['name_th' => $name]);
        makeImsLot($this->lab->id, $item, '2', '500');

        return $item;
    };

    // A keeper of the kind (default chemical) in the shopper's branch.
    $this->keeper = fn (string $kind = 'chemical', ?int $labId = null, array $extra = []) => labManagerUser(array_merge(['lab_id' => $labId ?? $this->lab->id, 'itemTypes' => [$kind]], $extra));

    $this->refill = fn (Item $item, ?User $user = null) => $this->actingAs($user ?? $this->shopper)->post(route('shop.request-refill', $item->ulid));
});

// --- ShopAvailability --------------------------------------------------------------------------------------------

test('ShopAvailability: orderable is the working stock only; total (working + IMS) is for display', function () {
    $item = makeItem();
    makeImsLot($this->lab->id, $item, '2', '500');                                       // 1000 in IMS
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '300.250000']);

    $stock = $this->availability->forItem($item, $this->lab->id);

    expect($stock['working'])->toBe('300.250000')
        ->and($stock['ims'])->toBe('1000.000000')
        ->and($stock['total'])->toBe('1300.250000')
        ->and($stock['orderable'])->toBe('300.250000');
});

test('ShopAvailability: an IMS-only item has orderable 0 even though total is not 0', function () {
    $item = makeItem();
    makeImsLot($this->lab->id, $item, '2', '500');

    $stock = $this->availability->forItem($item, $this->lab->id);

    expect($stock['orderable'])->toBe('0.000000')->and($stock['total'])->toBe('1000.000000');
});

test('ShopAvailability: only usable working stock counts — a place of this branch, SEALED/IN_USE, not expired, not used up', function () {
    $item = makeItem();
    $keep = fn (array $o) => makeContainer(array_merge(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '10.000000'], $o));

    $keep([]);                                                              // counts: 10
    $keep(['status' => 'IN_USE']);                                          // counts: 10
    $keep(['status' => 'QUARANTINE']);                                      // no
    $keep(['status' => 'EMPTY', 'remaining_qty_base' => '0.000000']);       // no
    $keep(['expiry_date' => now()->subDay()->toDateString()]);              // expired: no
    $keep(['expiry_date' => now()->addDays(5)->toDateString()]);            // counts: 10
    $keep(['location_id' => $this->otherLocation->id]);                     // another branch: no
    $keep(['location_id' => null]);                                         // no place: no

    // IMS: an expired lot does not count either.
    makeImsLot($this->lab->id, $item, '1', '500', ['expiry_date' => now()->subDay()->toDateString()]);
    makeImsLot($this->lab->id, $item, '1', '500', ['expiry_date' => now()->addYear()->toDateString()]);
    makeImsLot($this->otherLab->id, $item, '1', '500');

    $stock = $this->availability->forItem($item, $this->lab->id);

    expect($stock['working'])->toBe('30.000000')->and($stock['orderable'])->toBe('30.000000')->and($stock['ims'])->toBe('500.000000');
});

test('ShopAvailability::shelf keeps IMS-only items (so people can see them), drops items with nothing usable and other branches\' items', function () {
    $imsOnly = makeItem(['name_th' => 'ZSHELF ims only']);
    $workingOnly = makeItem(['name_th' => 'ZSHELF working only']);
    $nothing = makeItem(['name_th' => 'ZSHELF nothing']);
    $foreign = makeItem(['name_th' => 'ZSHELF foreign']);
    $expired = makeItem(['name_th' => 'ZSHELF expired ims']);
    makeImsLot($this->lab->id, $imsOnly);
    makeContainer(['item_id' => $workingOnly->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '5.000000']);
    makeImsLot($this->otherLab->id, $foreign);
    makeImsLot($this->lab->id, $expired, '1', '500', ['expiry_date' => now()->subDay()->toDateString()]);

    $ids = $this->availability->shelf($this->lab->id)->pluck('items.id');

    expect($ids->contains($imsOnly->id))->toBeTrue()
        ->and($ids->contains($workingOnly->id))->toBeTrue()
        ->and($ids->contains($nothing->id))->toBeFalse()
        ->and($ids->contains($foreign->id))->toBeFalse()
        ->and($ids->contains($expired->id))->toBeFalse();
});

// --- shop pages ----------------------------------------------------------------------------------------------------

test('an IMS-only item is on the shelf with the badge and the refill button, and has NO add-to-cart form', function () {
    $item = ($this->imsOnly)('สารเฉพาะคลังกลาง ZSHELFONLY');

    $page = $this->actingAs($this->shopper)->get(route('shop.index', ['search' => 'ZSHELFONLY']))->assertOk();

    $page->assertSee('สารเฉพาะคลังกลาง ZSHELFONLY')
        ->assertSee(__('working_stock.ims_only_badge'))
        ->assertSee(__('working_stock.btn_request_refill'))
        ->assertSee(route('shop.request-refill', $item->ulid), false)
        ->assertDontSee('action="'.route('shop.cart.add').'"', false);
});

test('a working-stock item on the shelf has the cart form and neither the badge nor the refill button', function () {
    $item = makeItem(['name_th' => 'สารคลังใช้งาน ZSHELFWORK']);
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '80.000000']);

    $page = $this->actingAs($this->shopper)->get(route('shop.index', ['search' => 'ZSHELFWORK']))->assertOk();

    $page->assertSee('action="'.route('shop.cart.add').'"', false)
        ->assertDontSee(__('working_stock.ims_only_badge'))
        ->assertDontSee(__('working_stock.btn_request_refill'))
        ->assertSee('80 g');
});

test('the product page of an IMS-only item opens (no 404), explains it is not orderable, offers the refill button and no cart form', function () {
    $item = ($this->imsOnly)();

    $page = $this->actingAs($this->shopper)->get(route('shop.show', $item->ulid))->assertOk();

    $page->assertSee(__('working_stock.ims_only_badge'))
        ->assertSee(__('working_stock.ims_only_hint'))
        ->assertSee(__('working_stock.btn_request_refill'))
        ->assertSee(route('shop.request-refill', $item->ulid), false)
        ->assertDontSee('action="'.route('shop.cart.add').'"', false);
});

test('the product page 404s for an item with nothing in this branch, in working stock or IMS', function () {
    $this->actingAs($this->shopper)->get(route('shop.show', makeItem()->ulid))->assertNotFound();
});

// --- cart / checkout -----------------------------------------------------------------------------------------------

test('cart.add refuses an item whose working stock is 0 (IMS only), with the Thai message, and the cart stays empty', function () {
    $item = ($this->imsOnly)('สารห้ามใส่ตะกร้า');

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '1', 'unit_id' => $this->g->id])
        ->assertSessionHasErrors(['cart' => __('working_stock.error.not_in_working', ['item' => 'สารห้ามใส่ตะกร้า'])]);

    expect(session('shop.cart.chemical'))->toBeNull();
});

test('cart.add refuses more than the working stock even when IMS would cover it, and names the maximum', function () {
    $item = makeItem(['name_th' => 'สารเกินคลังใช้งาน']);
    makeImsLot($this->lab->id, $item, '4', '500');                                        // 2000 g in IMS
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '100.000000']);

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '101', 'unit_id' => $this->g->id])
        ->assertSessionHasErrors(['cart' => __('working_stock.error.over_working', ['item' => 'สารเกินคลังใช้งาน', 'max' => '100', 'unit' => 'g'])]);
    expect(session('shop.cart.chemical'))->toBeNull();

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '100', 'unit_id' => $this->g->id])->assertSessionHasNoErrors();
    expect((float) session('shop.cart.chemical')[$item->ulid]['qty'])->toEqual(100.0);
});

test('cart.add counts the working stock in the unit asked for (0.2 kg = 200 g against 100 g working)', function () {
    $item = makeItem();
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '100.000000']);

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '0.2', 'unit_id' => Unit::where('code', 'kg')->firstOrFail()->id])
        ->assertSessionHasErrors('cart');
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '0.1', 'unit_id' => Unit::where('code', 'kg')->firstOrFail()->id])
        ->assertSessionHasNoErrors();
});

test('cart.update refuses a quantity above the working stock and keeps the old one', function () {
    $item = makeItem();
    makeImsLot($this->lab->id, $item, '4', '500');
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '100.000000']);
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '50', 'unit_id' => $this->g->id])->assertSessionHasNoErrors();

    $this->actingAs($this->shopper)->put(route('shop.cart.update', $item->ulid), ['qty' => '150'])->assertSessionHasErrors('cart');
    expect((float) session('shop.cart.chemical')[$item->ulid]['qty'])->toEqual(50.0);

    $this->actingAs($this->shopper)->put(route('shop.cart.update', $item->ulid), ['qty' => '100'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) session('shop.cart.chemical')[$item->ulid]['qty'])->toEqual(100.0);
});

test('checkout re-checks against working stock: when it dropped after the item went into the cart, no requisition is made', function () {
    $item = makeItem(['name_th' => 'สารที่ของหมดระหว่างทาง']);
    makeImsLot($this->lab->id, $item, '4', '500');
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '100.000000']);
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '80', 'unit_id' => $this->g->id])->assertSessionHasNoErrors();

    $container->update(['remaining_qty_base' => '10.000000']);

    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['type' => 'chemical', 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลอง'])
        ->assertRedirect(route('shop.cart', ['type' => 'chemical']))->assertSessionHasErrors('cart');

    expect(Requisition::count())->toBe(0)->and(session('shop.cart.chemical'))->not->toBeNull();
});

test('checkout refuses a cart line whose item is IMS-only (a stale or hand-made session), and the cart page shows why', function () {
    $item = ($this->imsOnly)('สารค้างในตะกร้าเก่า');
    $this->withSession(['shop.cart.chemical' => [$item->ulid => ['qty' => '5', 'unit_id' => $this->g->id]]]);

    $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'chemical']))->assertOk()
        ->assertSee(__('working_stock.error.not_in_working', ['item' => 'สารค้างในตะกร้าเก่า']));

    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['type' => 'chemical', 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลอง'])
        ->assertSessionHasErrors(['cart' => __('working_stock.error.not_in_working', ['item' => 'สารค้างในตะกร้าเก่า'])]);

    expect(Requisition::count())->toBe(0);
});

// --- "ask for a refill" ---------------------------------------------------------------------------------------------

test('requestRefill is 403 unless the item is active, the requester may shop in a branch, and this branch\'s IMS holds some of it', function () {
    $item = ($this->imsOnly)();
    ($this->keeper)();    // would be a recipient, so a refusal is not "no keeper"

    // not logged in
    $this->post(route('shop.request-refill', $item->ulid))->assertRedirect(route('login'));

    // no requisition.create (no role) / no branch
    ($this->refill)($item, User::factory()->create(['lab_id' => $this->lab->id]))->assertForbidden();
    ($this->refill)($item, staffUser(['lab_id' => null]))->assertForbidden();

    // IMS stock only in ANOTHER branch, or only expired / used up here
    $foreign = makeItem();
    makeImsLot($this->otherLab->id, $foreign);
    ($this->refill)($foreign)->assertForbidden();
    $expired = makeItem();
    makeImsLot($this->lab->id, $expired, '1', '500', ['expiry_date' => now()->subDay()->toDateString()]);
    ($this->refill)($expired)->assertForbidden();
    $empty = makeItem();
    makeImsLot($this->lab->id, $empty, '1', '500', ['qty_remaining_base' => '0.000000']);
    ($this->refill)($empty)->assertForbidden();
    ($this->refill)(makeItem())->assertForbidden();   // nothing anywhere

    // inactive item
    $item->update(['is_active' => false]);
    ($this->refill)($item)->assertForbidden();

    expect(Notification::count())->toBe(0)->and(AuditLog::where('action', 'SHOP_REFILL_REQUEST')->count())->toBe(0);
});

test('requestRefill tells ONLY the active keepers of that kind in the requester\'s branch, in the system only (no email), and writes one audit row', function () {
    Mail::fake();
    $item = ($this->imsOnly)('สารขอเติม');

    $chemKeeper = ($this->keeper)('chemical');
    $auditorKeeper = auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical']]);
    $supplyKeeper = ($this->keeper)('consumable');                                     // other kind
    $otherBranchKeeper = ($this->keeper)('chemical', $this->otherLab->id);              // other branch
    $inactiveKeeper = ($this->keeper)('chemical', null, ['is_active' => false]);
    $admin = adminUser(['lab_id' => $this->lab->id]);
    grantItemTypes($admin, ['chemical', 'consumable']);                                 // ADMIN is never a recipient
    $scientist = scientistUser(['lab_id' => $this->lab->id]);                           // no kinds

    ($this->refill)($item)
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('working_stock.refill_sent', ['count' => 2, 'item' => 'สารขอเติม']));

    $got = Notification::where('type', 'shop.refill_request')->pluck('user_id')->all();
    expect($got)->toHaveCount(2)->toContain($chemKeeper->id, $auditorKeeper->id);
    foreach ([$supplyKeeper, $otherBranchKeeper, $inactiveKeeper, $admin, $scientist, $this->shopper] as $nobody) {
        expect(Notification::where('user_id', $nobody->id)->count())->toBe(0);
    }

    $note = Notification::where('user_id', $chemKeeper->id)->firstOrFail();
    expect($note->title)->toBe(__('working_stock.refill_notify_title'))
        ->and($note->body)->toContain('สารขอเติม')->toContain($this->shopper->full_name)->toContain('1000')
        ->and($note->link_url)->toBe(route('ims.lots.index', ['search' => $item->item_code]));

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $audit = AuditLog::where('action', 'SHOP_REFILL_REQUEST')->get();
    expect($audit)->toHaveCount(1)
        ->and($audit->first()->user_id)->toBe($this->shopper->id)
        ->and($audit->first()->entity_id)->toBe($item->id)
        ->and($audit->first()->new_value['kind'])->toBe('chemical')
        ->and($audit->first()->new_value['recipients'])->toBe(2)
        ->and($audit->first()->new_value['lab_id'])->toBe($this->lab->id);
});

test('requestRefill for a consumable goes to the consumable keepers, not the chemical ones', function () {
    $item = ($this->imsOnly)('วัสดุขอเติม', ['consumable' => true]);
    $chem = ($this->keeper)('chemical');
    $supply = ($this->keeper)('consumable');

    ($this->refill)($item)->assertSessionHasNoErrors();

    expect(Notification::where('user_id', $supply->id)->count())->toBe(1)->and(Notification::where('user_id', $chem->id)->count())->toBe(0);
    expect(AuditLog::where('action', 'SHOP_REFILL_REQUEST')->firstOrFail()->new_value['kind'])->toBe('consumable');
});

test('requestRefill with nobody keeping that kind in the branch says so, sends nothing, and does not use up the quota', function () {
    $item = ($this->imsOnly)();
    ($this->keeper)('consumable');                       // a keeper of the wrong kind does not help
    ($this->keeper)('chemical', $this->otherLab->id);    // nor one of another branch

    ($this->refill)($item)->assertSessionHasErrors(['cart' => __('working_stock.refill_no_keeper')]);
    ($this->refill)($item)->assertSessionHasErrors(['cart' => __('working_stock.refill_no_keeper')]);   // still "no keeper", never "already asked today"

    expect(Notification::count())->toBe(0)->and(AuditLog::where('action', 'SHOP_REFILL_REQUEST')->count())->toBe(0);

    // A keeper appears: the same click now works, so the failed attempts did not spend the daily slot.
    $keeper = ($this->keeper)('chemical');
    ($this->refill)($item)->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $keeper->id)->count())->toBe(1);
});

test('requestRefill twice for the same item on the same day: the second is refused and notifies nobody again; a day later it works', function () {
    $item = ($this->imsOnly)();
    $keeper = ($this->keeper)();

    ($this->refill)($item)->assertSessionHasNoErrors();
    ($this->refill)($item)->assertSessionHasErrors(['cart' => __('working_stock.refill_already_today')]);

    expect(Notification::where('user_id', $keeper->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'SHOP_REFILL_REQUEST')->count())->toBe(1);

    // Another requester may still ask for the same item.
    ($this->refill)($item, staffUser(['lab_id' => $this->lab->id]))->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $keeper->id)->count())->toBe(2);

    $this->travel(25)->hours();
    ($this->refill)($item)->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $keeper->id)->count())->toBe(3);
});

test('requestRefill has a daily ceiling of 20 requests per user (the 21st is refused), other users are not affected', function () {
    $keeper = ($this->keeper)();

    for ($i = 1; $i <= 20; $i++) {
        $item = makeItem(['name_th' => "สารเพดาน {$i}"]);
        makeImsLot($this->lab->id, $item);
        ($this->refill)($item)->assertSessionHasNoErrors();
    }
    expect(Notification::where('user_id', $keeper->id)->count())->toBe(20);

    $twentyFirst = makeItem(['name_th' => 'สารเพดาน 21']);
    makeImsLot($this->lab->id, $twentyFirst);
    ($this->refill)($twentyFirst)->assertSessionHasErrors(['cart' => __('working_stock.refill_user_limit')]);
    expect(Notification::where('user_id', $keeper->id)->count())->toBe(20)
        ->and(AuditLog::where('action', 'SHOP_REFILL_REQUEST')->count())->toBe(20);

    $someoneElse = staffUser(['lab_id' => $this->lab->id]);
    ($this->refill)($twentyFirst, $someoneElse)->assertSessionHasNoErrors();
    expect(Notification::where('user_id', $keeper->id)->count())->toBe(21);
});
