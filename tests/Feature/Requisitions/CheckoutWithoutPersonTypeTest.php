<?php

use App\Models\Requisition;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->lab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->g = Unit::where('code', 'g')->firstOrFail();
    $this->item = makeItem(['name_th' => 'สารทดสอบสถานะผู้ขอ']);
    makeContainer(['item_id' => $this->item->id, 'location_id' => $this->location->id, 'remaining_qty_base' => '500.000000']);
});

test('checkout with no person type goes back to the complete-profile page and creates no requisition', function () {
    $user = staffUser(['lab_id' => $this->lab->id]);
    $this->actingAs($user)->post(route('shop.cart.add'), ['item' => $this->item->ulid, 'qty' => '10', 'unit_id' => $this->g->id])->assertRedirect();

    // Profile flagged complete, but no person type (e.g. set directly in the database).
    $user->forceFill(['person_type' => null])->save();

    $this->actingAs($user->fresh())
        ->post(route('shop.checkout'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลองที่ 1'])
        ->assertRedirect(route('account.complete-profile'))
        ->assertSessionHas('status', __('requisitions.person_type_missing'));

    expect(Requisition::count())->toBe(0);
});

test('requisition form and store with no person type go back to the complete-profile page and create nothing', function () {
    $user = staffUser(['lab_id' => $this->lab->id]);
    $user->forceFill(['person_type' => null])->save();
    $user = $user->fresh();

    $this->actingAs($user)->get(route('requisitions.create'))
        ->assertRedirect(route('account.complete-profile'))
        ->assertSessionHas('status', __('requisitions.person_type_missing'));

    $this->actingAs($user)
        ->post(route('requisitions.store'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลองที่ 1'])
        ->assertRedirect(route('account.complete-profile'))
        ->assertSessionHas('status', __('requisitions.person_type_missing'));

    expect(Requisition::count())->toBe(0);
});

test('requisition form and store with a person type still work', function () {
    $user = staffUser(['lab_id' => $this->lab->id]);

    $this->actingAs($user)->get(route('requisitions.create'))->assertOk();
    $this->actingAs($user)
        ->post(route('requisitions.store'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลองที่ 1'])
        ->assertRedirect();

    expect(Requisition::firstOrFail()->requester_status)->toBe('STAFF');
});

test('checkout with a person type still creates the requisition', function () {
    $user = staffUser(['lab_id' => $this->lab->id]);
    $this->actingAs($user)->post(route('shop.cart.add'), ['item' => $this->item->ulid, 'qty' => '10', 'unit_id' => $this->g->id])->assertRedirect();

    $this->actingAs($user)
        ->post(route('shop.checkout'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลองที่ 1'])
        ->assertRedirect();

    $requisition = Requisition::firstOrFail();
    expect($requisition->requester_status)->toBe('STAFF')->and($requisition->requester_id)->toBe($user->id);
});

test('BR-02: checkout by a STUDENT with no advisor goes to complete-profile and creates no requisition', function () {
    $user = studentUser(['lab_id' => $this->lab->id, 'advisor_id' => null]);
    $this->actingAs($user)->post(route('shop.cart.add'), ['item' => $this->item->ulid, 'qty' => '10', 'unit_id' => $this->g->id])->assertRedirect();

    $this->actingAs($user)
        ->post(route('shop.checkout'), ['request_type' => ['CHEMICAL'], 'purpose_type' => 'TEACHING', 'purpose_detail' => 'ทดลองที่ 1'])
        ->assertRedirect(route('account.complete-profile'))
        ->assertSessionHas('status', __('requisitions.advisor_missing'));

    expect(Requisition::count())->toBe(0);
});
