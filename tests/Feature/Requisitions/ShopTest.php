<?php

use App\Domain\Inventory\Services\ImsService;
use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\ImsReceipt;
use App\Models\Item;
use App\Models\Lab;
use App\Models\Requisition;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const SHOP_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

beforeEach(function () {
    Storage::fake('signatures');
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->shopper = staffUser(['lab_id' => $this->lab->id]);
    $this->g = Unit::where('code', 'g')->firstOrFail();
    $this->ml = Unit::where('code', 'mL')->firstOrFail();

    // A confirmed IMS lot of $qty grams (500 g packs) in a branch, optionally with a storage location.
    $this->imsLot = function (Item $item, string $qty = '1000', ?int $labId = null, bool $withLocation = true): ImsLot {
        $labId ??= $this->lab->id;
        $manager = labManagerUser(['lab_id' => $labId]);
        $receipt = ImsReceipt::create(['lab_id' => $labId, 'doc_no' => 'PO-'.fake()->unique()->numerify('####'), 'status' => 'DRAFT', 'created_by' => $manager->id]);
        $receipt->lines()->create([
            'line_no' => 1, 'item_id' => $item->id, 'item_code_raw' => $item->item_code, 'qty' => $qty,
            'unit_id' => $this->g->id, 'pack_qty' => (string) ((float) $qty / 500), 'unit_price' => '100',
        ]);
        app(ImsService::class)->confirm($receipt, $manager);
        $lot = ImsLot::where('ims_receipt_id', $receipt->id)->firstOrFail();
        if ($withLocation) {
            $lot->update(['location_id' => makeLocationForLab(Lab::findOrFail($labId))->id]);
        }

        return $lot->fresh();
    };
});

test('the shelf lists items the branch can hand out, working stock plus IMS, and nothing else', function () {
    $inIms = makeItem(['name_th' => 'สารในคลังกลาง']);
    $inWorking = makeItem(['name_th' => 'สารในคลังใช้งาน']);
    makeItem(['name_th' => 'สารที่ไม่มีของเลย']);
    $otherBranch = makeItem(['name_th' => 'สารของสาขาอื่น']);

    ($this->imsLot)($inIms, '1000');
    makeContainer(['item_id' => $inWorking->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '200.000000']);
    ($this->imsLot)($otherBranch, '1000', $this->otherLab->id);

    $page = $this->actingAs($this->shopper)->get(route('shop.index'))->assertOk();
    $page->assertSee('สารในคลังกลาง')->assertSee('สารในคลังใช้งาน')->assertSee('1000 g')->assertSee('200 g')
        ->assertDontSee('สารที่ไม่มีของเลย')->assertDontSee('สารของสาขาอื่น');

    $this->actingAs($this->shopper)->get(route('shop.index', ['search' => 'คลังกลาง']))->assertOk()
        ->assertSee('สารในคลังกลาง')->assertDontSee('สารในคลังใช้งาน');
});

test('a guest is sent to login and a user with no role cannot shop', function () {
    $this->get(route('shop.index'))->assertRedirect(route('login'));
    $roleless = User::factory()->create(['lab_id' => $this->lab->id]);
    $this->actingAs($roleless)->get(route('shop.index'))->assertForbidden();
});

test('items go into the cart, merge when added again, and cannot exceed the stock', function () {
    $item = makeItem(['name_th' => 'สารทดสอบตะกร้า']);
    ($this->imsLot)($item, '1000');
    makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '100.000000']);   // 1100 g in total

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '300', 'unit_id' => $this->g->id])->assertRedirect();
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '0.2', 'unit_id' => Unit::where('code', 'kg')->firstOrFail()->id])->assertRedirect();

    $cart = session('shop.cart');
    expect((float) $cart[$item->ulid]['qty'])->toEqual(500.0)->and($cart[$item->ulid]['unit_id'])->toBe($this->g->id);

    // 500 + 700 would be 1200 > 1100.
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '700', 'unit_id' => $this->g->id])->assertSessionHasErrors('cart');
    expect((float) session('shop.cart')[$item->ulid]['qty'])->toEqual(500.0);

    // A volume unit for a mass item is refused.
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $item->ulid, 'qty' => '1', 'unit_id' => $this->ml->id])->assertSessionHasErrors('cart');

    $this->actingAs($this->shopper)->get(route('shop.cart'))->assertOk()->assertSee('สารทดสอบตะกร้า');
    $this->actingAs($this->shopper)->put(route('shop.cart.update', $item->ulid), ['qty' => '250'])->assertRedirect(route('shop.cart'));
    expect((float) session('shop.cart')[$item->ulid]['qty'])->toEqual(250.0);
    $this->actingAs($this->shopper)->delete(route('shop.cart.remove', $item->ulid))->assertRedirect(route('shop.cart'));
    expect(session('shop.cart'))->toBe([]);
});

test('checking out turns the cart into a submitted requisition with one line per item, and empties the cart', function () {
    $a = makeItem(['name_th' => 'สาร เอ']);
    $b = makeItem(['name_th' => 'สาร บี']);
    ($this->imsLot)($a, '1000');
    ($this->imsLot)($b, '500');

    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $a->ulid, 'qty' => '100', 'unit_id' => $this->g->id]);
    $this->actingAs($this->shopper)->post(route('shop.cart.add'), ['item' => $b->ulid, 'qty' => '50', 'unit_id' => $this->g->id]);

    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['purpose_type' => 'TEACHING'])->assertSessionHasErrors('request_type');
    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลองที่ 3'])->assertRedirect();

    $requisition = Requisition::firstOrFail();
    expect($requisition->status)->toBe('SUBMITTED')->and($requisition->lab_id)->toBe($this->lab->id)
        ->and($requisition->requester_id)->toBe($this->shopper->id)->and($requisition->items)->toHaveCount(2)
        ->and((float) $requisition->items->first()->qty_requested_base)->toEqual(100.0);
    expect(session('shop.cart'))->toBeNull();

    // Nothing is reserved or moved at checkout.
    expect((float) ImsLot::where('item_id', $a->id)->firstOrFail()->qty_remaining_base)->toEqual(1000.0);
});

test('checking out an empty cart is refused', function () {
    $this->actingAs($this->shopper)->post(route('shop.checkout'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING'])->assertSessionHasErrors('cart');
    expect(Requisition::count())->toBe(0);
});

test('dispensing uses working stock first and cuts only the shortfall from IMS, in whole bottles', function () {
    $item = makeItem(['name_th' => 'สารตัดอัตโนมัติ']);
    $lot = ($this->imsLot)($item, '1000');          // 2 packs of 500 g
    $old = makeContainer(['item_id' => $item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '10.000000', 'status' => 'IN_USE']);

    $requisition = makeRequisition($this->shopper);
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $this->g, '30');
    $requisition->update(['status' => 'APPROVED']);
    $line = $requisition->items()->firstOrFail();
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $line]), [
        'qty_issued' => '30', 'unit_id' => $this->g->id, 'signature_image' => SHOP_PNG,
    ])->assertRedirect(route('requisitions.issue.create', $requisition));

    expect($requisition->fresh()->status)->toBe('ISSUED')
        ->and((float) $line->fresh()->qty_issued_base)->toEqual(30.0)
        ->and((float) $lot->fresh()->qty_remaining_base)->toEqual(500.0)                  // exactly one 500 g bottle left IMS
        ->and((float) $old->fresh()->remaining_qty_base)->toEqual(0.0);
    $new = Container::where('ims_lot_id', $lot->id)->firstOrFail();
    expect((float) $new->remaining_qty_base)->toEqual(480.0);                            // 500 g bottle, 20 g used
    expect(ImsMovement::where('ims_lot_id', $lot->id)->where('type', 'ISSUE')->count())->toBe(1);
});

test('when working stock already covers the request, IMS is not touched', function () {
    $item = makeItem();
    $lot = ($this->imsLot)($item, '1000');
    stockedContainer($item->id, '100.000000', $this->shopper)->update(['location_id' => $this->location->id]);

    $requisition = makeRequisition($this->shopper);
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $this->g, '30');
    $requisition->update(['status' => 'APPROVED']);
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $requisition->items()->firstOrFail()]), [
        'qty_issued' => '30', 'unit_id' => $this->g->id, 'signature_image' => SHOP_PNG,
    ])->assertRedirect();

    expect((float) $lot->fresh()->qty_remaining_base)->toEqual(1000.0)->and(Container::where('ims_lot_id', $lot->id)->count())->toBe(0);
});

test('a lot with no storage location cannot be cut automatically, and nothing moves', function () {
    $item = makeItem();
    $lot = ($this->imsLot)($item, '1000', null, false);

    $requisition = makeRequisition($this->shopper);
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $this->g, '30');
    $requisition->update(['status' => 'APPROVED']);
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $requisition->items()->firstOrFail()]), [
        'qty_issued' => '30', 'unit_id' => $this->g->id, 'signature_image' => SHOP_PNG,
    ])->assertSessionHasErrors('issue');

    expect((float) $lot->fresh()->qty_remaining_base)->toEqual(1000.0)->and($requisition->fresh()->status)->toBe('APPROVED');
});

test('a bad receiver signature never cuts stock out of IMS', function () {
    $item = makeItem();
    $lot = ($this->imsLot)($item, '1000');

    $requisition = makeRequisition($this->shopper);
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $this->g, '30');
    $requisition->update(['status' => 'APPROVED']);
    $scientist = scientistUser(['lab_id' => $this->lab->id]);

    $this->actingAs($scientist)->post(route('requisitions.items.issue-auto', [$requisition, $requisition->items()->firstOrFail()]), [
        'qty_issued' => '30', 'unit_id' => $this->g->id, 'otp_code' => '000000',
    ])->assertSessionHasErrors();

    expect((float) $lot->fresh()->qty_remaining_base)->toEqual(1000.0);
});

test('the product page shows what the item is, where each bit sits and when it expires, with no prices', function () {
    $item = makeItem(['name_th' => 'สารหน้ารายละเอียด', 'name_en' => 'Detail Chemical', 'cas_no' => '64-17-5', 'specification' => 'AR grade ขวดแก้ว']);
    $lot = ($this->imsLot)($item, '1000');
    $lot->update(['lot_no' => 'LOT-XY', 'expiry_date' => '2571-02-01', 'pack_size_base' => '500']);
    $lot->groups()->create(['pack_count' => '1', 'location_id' => $lot->location_id, 'expiry_date' => '2570-12-01']);
    $bottle = stockedContainer($item->id, '100.000000', $this->shopper);
    $bottle->update(['location_id' => $this->location->id, 'expiry_date' => '2571-05-05']);

    $page = $this->actingAs($this->shopper)->get(route('shop.show', $item->ulid))->assertOk();
    $page->assertSee('สารหน้ารายละเอียด')->assertSee('Detail Chemical')->assertSee('64-17-5')->assertSee('AR grade')
        ->assertSee('LOT-XY')->assertSee('01/12/2570')->assertSee('01/02/2571')->assertSee('05/05/2571')
        ->assertSee('1100 g')->assertSee($this->location->name)
        ->assertDontSee('100.00')->assertSee(route('shop.cart.add'), false);

    // The shelf links to it.
    $this->actingAs($this->shopper)->get(route('shop.index'))->assertSee(route('shop.show', $item->ulid), false);
});

test('the product page 404s for an item the branch cannot hand out, and is closed to roleless users', function () {
    $empty = makeItem();
    $otherBranch = makeItem();
    ($this->imsLot)($otherBranch, '1000', $this->otherLab->id);

    $this->actingAs($this->shopper)->get(route('shop.show', $empty->ulid))->assertNotFound();
    $this->actingAs($this->shopper)->get(route('shop.show', $otherBranch->ulid))->assertNotFound();

    $roleless = User::factory()->create(['lab_id' => $this->lab->id]);
    $this->actingAs($roleless)->get(route('shop.show', $empty->ulid))->assertForbidden();
});

test('the shelf has one tab per item type with a count, and a tab narrows the list', function () {
    $chemical = makeItem(['name_th' => 'สารเคมีในแท็บ']);
    $media = makeItem(['name_th' => 'อาหารเลี้ยงเชื้อในแท็บ', 'category_id' => \App\Models\ItemCategory::where('code', '!=', 'CHEMICAL')->where('is_chemical', true)->firstOrFail()->id]);
    ($this->imsLot)($chemical, '500');
    ($this->imsLot)($media, '500');
    $mediaCategory = $media->category;

    $all = $this->actingAs($this->shopper)->get(route('shop.index'))->assertOk();
    $all->assertSee('สารเคมีในแท็บ')->assertSee('อาหารเลี้ยงเชื้อในแท็บ')->assertSee($mediaCategory->name_th)->assertSee('(2)');

    $this->actingAs($this->shopper)->get(route('shop.index', ['category' => $mediaCategory->id]))->assertOk()
        ->assertSee('อาหารเลี้ยงเชื้อในแท็บ')->assertDontSee('สารเคมีในแท็บ');

    // Searching keeps the chosen tab (hidden field) and the counts follow the search.
    $this->actingAs($this->shopper)->get(route('shop.index', ['category' => $mediaCategory->id, 'search' => 'ไม่มีชื่อนี้']))->assertOk()
        ->assertDontSee('อาหารเลี้ยงเชื้อในแท็บ')->assertSee('name="category" value="'.$mediaCategory->id.'"', false);
});
