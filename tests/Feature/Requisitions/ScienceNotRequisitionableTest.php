<?php

use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\RequisitionService;
use App\Domain\Requisition\Services\ShopAvailability;
use App\Models\AuditLog;
use App\Models\IssueTransaction;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/*
 * CEO 2026-11-05 (ITM-2610-063): science (loan-and-return) materials are lent, never requisitioned. Every road a
 * requisition could take them has to be shut on the SERVER (not only hidden in the picker): the shop shelf and product
 * page, the cart, checkout, the refill button, the item search, adding a line, and dispensing. Each test gives the
 * science item real stock and compares with a consumable "control" item that behaves normally, so a pass means
 * "refused because it is science", not "refused because there was nothing".
 */

beforeEach(function () {
    $this->lab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->shopper = staffUser(['lab_id' => $this->lab->id]);
    $this->g = Unit::where('code', 'g')->firstOrFail();
    $this->msg = (string) __('requisitions.validation.item_not_requisitionable');

    $this->science = scienceItem(['name_th' => 'ZSCI วัสดุยืมคืน']);
    $this->control = consumableItem(['name_th' => 'ZCON วัสดุควบคุม']);
    foreach ([$this->science, $this->control] as $item) {
        makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '500.000000']);
        makeImsLot($this->lab->id, $item, '2', '500');
    }
});

test('ShopAvailability::shelf shows the consumable control but never the science item, even though it has working stock and IMS stock', function () {
    $ids = app(ShopAvailability::class)->shelf($this->lab->id)->pluck('items.id');

    expect($ids->contains($this->control->id))->toBeTrue()
        ->and($ids->contains($this->science->id))->toBeFalse();
});

test('the shop page lists the control but not the science item, and a ?type=science URL is rejected by validation', function () {
    $page = $this->actingAs($this->shopper)->get(route('shop.index'))->assertOk();
    $page->assertSee('ZCON วัสดุควบคุม')->assertDontSee('ZSCI วัสดุยืมคืน');

    $this->actingAs($this->shopper)->get(route('shop.index', ['search' => 'ZSCI']))->assertOk()->assertDontSee('ZSCI วัสดุยืมคืน');
    $this->actingAs($this->shopper)->get(route('shop.index', ['type' => 'science']))->assertSessionHasErrors('type');

    // The category filter never offers the science category.
    $this->actingAs($this->shopper)->get(route('shop.index'))->assertDontSee('วัสดุวิทยาศาสตร์ (ยืม-คืน)');
});

test('the product page of a science item is a 404 even by typing the URL; the control opens', function () {
    $this->actingAs($this->shopper)->get(route('shop.show', $this->control->ulid))->assertOk();
    $this->actingAs($this->shopper)->get(route('shop.show', $this->science->ulid))->assertNotFound();
});

test('cart.add refuses a science item with the Thai message and leaves both baskets empty; the control goes in', function () {
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $this->science->ulid, 'qty' => '10', 'unit_id' => $this->g->id])
        ->assertSessionHasErrors(['cart' => $this->msg]);

    expect(session('shop.cart.chemical', []))->toBe([])->and(session('shop.cart.consumable', []))->toBe([])->and(session('shop.cart.science'))->toBeNull();

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $this->control->ulid, 'qty' => '10', 'unit_id' => $this->g->id])->assertSessionHasNoErrors();
    expect(array_keys(session('shop.cart.consumable')))->toBe([$this->control->ulid]);
});

test('checkout refuses a science line even if one got into the basket (item re-categorised after it was added), and creates no requisition', function () {
    // The line was a normal consumable when it went in...
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $this->control->ulid, 'qty' => '10', 'unit_id' => $this->g->id])->assertSessionHasNoErrors();
    // ...then the item was moved to the science category.
    $this->control->update(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);

    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['type' => 'consumable', 'purpose_type' => 'TEACHING'])
        ->assertSessionHasErrors(['cart' => $this->msg]);

    expect(Requisition::count())->toBe(0);
});

test('checkout and the cart page refuse type=science outright', function () {
    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['type' => 'science', 'purpose_type' => 'TEACHING'])->assertSessionHasErrors('type');
    $this->actingAs($this->shopper)->get(route('shop.cart', ['type' => 'science']))->assertSessionHasErrors('type');

    expect(Requisition::count())->toBe(0);
});

test('requestRefill: ItemPolicy::requestRefill is false for a science item that sits in IMS, and the route sends nobody a notice', function () {
    $keeper = labManagerUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical', 'consumable', 'science']]);
    // IMS only (no working stock) science item, and the same for a consumable control.
    $sciImsOnly = scienceItem(['name_th' => 'ZSCI ims only']);
    $conImsOnly = consumableItem(['name_th' => 'ZCON ims only']);
    makeImsLot($this->lab->id, $sciImsOnly, '2', '500');
    makeImsLot($this->lab->id, $conImsOnly, '2', '500');

    expect(Gate::forUser($this->shopper)->allows('requestRefill', $conImsOnly))->toBeTrue()
        ->and(Gate::forUser($this->shopper)->allows('requestRefill', $sciImsOnly))->toBeFalse();

    $this->actingAs($this->shopper)->post(route('shop.request-refill', $sciImsOnly->ulid))->assertForbidden();

    expect(Notification::where('user_id', $keeper->id)->count())->toBe(0)
        ->and(AuditLog::where('action', 'SHOP_REFILL_REQUEST')->count())->toBe(0);

    // The control still works through the same route (so the 403 above is about science only).
    $this->actingAs($this->shopper)->post(route('shop.request-refill', $conImsOnly->ulid))->assertSessionHasNoErrors();
});

test('requisitions.items.search never returns a science item; the control is found', function () {
    $json = $this->actingAs($this->shopper)->getJson(route('requisitions.items.search', ['q' => 'ZSCI']))->assertOk()->json();
    expect($json)->toBe([]);

    $all = $this->actingAs($this->shopper)->getJson(route('requisitions.items.search'))->assertOk()->json();
    expect(collect($all)->pluck('id')->all())->toContain($this->control->id)->not->toContain($this->science->id);

    $found = $this->actingAs($this->shopper)->getJson(route('requisitions.items.search', ['q' => 'ZCON']))->assertOk()->json();
    expect(collect($found)->pluck('id')->all())->toBe([$this->control->id]);
});

test('requisitions.items.store refuses a science item (validation error on item_id with the Thai message) and adds no line; the control is added', function () {
    $requester = studentUser(['lab_id' => $this->lab->id]);
    $requisition = makeRequisition($requester, ['request_type' => ['CONSUMABLE']]);

    $this->actingAs($requester)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $this->science->id, 'qty_requested' => '5', 'unit_id' => $this->g->id,
    ])->assertSessionHasErrors(['item_id' => $this->msg]);
    expect($requisition->items()->count())->toBe(0);

    $this->actingAs($requester)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $this->control->id, 'qty_requested' => '5', 'unit_id' => $this->g->id,
    ])->assertSessionHasNoErrors();
    expect($requisition->items()->pluck('item_id')->all())->toBe([$this->control->id]);
});

test('RequisitionService::addLine refuses a science item itself (not only the form request) with the same message', function () {
    $requisition = makeRequisition(staffUser(['lab_id' => $this->lab->id]), ['request_type' => ['CONSUMABLE']]);

    expect(fn () => app(RequisitionService::class)->addLine($requisition, $this->science, $this->g, '5'))
        ->toThrow(InvalidRequisitionTransitionException::class, $this->msg);
    // Also when the draft was filed under no particular type.
    $open = makeRequisition(staffUser(['lab_id' => $this->lab->id]), ['request_type' => []]);
    expect(fn () => app(RequisitionService::class)->addLine($open, $this->science, $this->g, '5'))
        ->toThrow(InvalidRequisitionTransitionException::class, $this->msg);

    expect($requisition->items()->count())->toBe(0)->and($open->items()->count())->toBe(0);
    app(RequisitionService::class)->addLine($requisition, $this->control, $this->g, '5');
    expect($requisition->items()->count())->toBe(1);
});

test('RequisitionService::addLine sees an item that was re-categorised to science a moment ago (the kind is read fresh, not from the passed model)', function () {
    $requisition = makeRequisition(staffUser(['lab_id' => $this->lab->id]), ['request_type' => ['CONSUMABLE']]);
    $stale = Item::with('category')->findOrFail($this->control->id);   // still looks like a consumable in memory

    $this->control->update(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);

    expect(fn () => app(RequisitionService::class)->addLine($requisition, $stale, $this->g, '5'))
        ->toThrow(InvalidRequisitionTransitionException::class, $this->msg);
});

test('the requisition create / store routes only take the two requisition kinds', function () {
    $this->actingAs($this->shopper)->post(route('requisitions.store'), ['type' => 'science', 'purpose_type' => 'TEACHING'])->assertSessionHasErrors('type');
    expect(Requisition::count())->toBe(0);

    // A ?type=science link falls back to "no kind chosen" instead of opening a science requisition form.
    $this->actingAs($this->shopper)->get(route('requisitions.create', ['type' => 'science']))->assertOk();
});

test('dispensing refuses a science line on an old approved document (item moved to science later): nothing issued, no stock moved, status kept; the issue page still opens', function () {
    $item = makeItem(['name_th' => 'ZOLD เดิมเป็นสารเคมี']);
    $requisition = makeRequisition($this->shopper, ['status' => 'DRAFT']);
    app(RequisitionService::class)->addLine($requisition, $item, $this->g, '30');
    $requisition->update(['status' => 'APPROVED']);
    $line = $requisition->items()->firstOrFail();
    $container = makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '100.000000']);

    // The item is re-categorised after approval (ItemUpdater would refuse this; here the row is changed directly).
    $item->update(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $this->actingAs($scientist)->post(route('requisitions.issue-all', $requisition), [
        'lines' => [$line->id => ['qty' => '30', 'unit_id' => $this->g->id]],
    ])->assertRedirect(route('requisitions.issue.create', $requisition))->assertSessionHasErrors('issue');

    expect(session('errors')->get('issue')[0])->toContain($this->msg);
    expect($requisition->fresh()->status)->toBe('APPROVED')
        ->and((float) $line->fresh()->qty_issued_base)->toEqual(0.0)
        ->and((float) $container->fresh()->remaining_qty_base)->toEqual(100.0)
        ->and(IssueTransaction::where('requisition_item_id', $line->id)->count())->toBe(0);

    $this->actingAs($scientist)->get(route('requisitions.issue.create', $requisition))->assertOk();
});

test('a finished document with a science line (item re-categorised after it was issued) still opens for its requester and for the branch manager, and its PDF does not break', function () {
    $item = makeItem(['name_th' => 'ZDONE เสร็จแล้ว']);
    $requisition = requisitionWithItem($item, 'ISSUED', $this->shopper);
    $requisition->update(['lab_id' => $this->lab->id]);
    $item->update(['category_id' => categoryId(\App\Models\ItemCategory::LOAN_CODE)]);

    $manager = auditorUser(['lab_id' => $this->lab->id, 'itemTypes' => ['chemical', 'consumable', 'science']]);

    $this->actingAs($this->shopper)->get(route('requisitions.show', $requisition))->assertOk()->assertSee('ZDONE เสร็จแล้ว');
    $this->actingAs($manager)->get(route('requisitions.show', $requisition))->assertOk();
    $this->actingAs($this->shopper)->get(route('requisitions.index'))->assertOk();
    $this->actingAs($manager)->get(route('requisitions.manage'))->assertOk();
    $this->actingAs($this->shopper)->get(route('requisitions.pdf', $requisition))->assertOk();
});
