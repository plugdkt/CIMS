<?php

use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the item search endpoint only returns items with stock in the requester\'s own lab', function () {
    [$student, $ownLab] = studentUserWithLab();
    $otherLab = makeLab();

    $inOwnLab = makeItem(['name_th' => 'โซเดียมไฮดรอกไซด์ AR']);
    stockItemInLab($inOwnLab->id, $ownLab->id);

    $inOtherLab = makeItem(['name_th' => 'โซเดียมคลอไรด์ AR']);
    stockItemInLab($inOtherLab->id, $otherLab->id);

    $response = $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'โซเดียม']));

    $response->assertOk();
    $labels = collect($response->json())->pluck('label');
    expect($labels->contains(fn ($label) => str_contains($label, 'โซเดียมไฮดรอกไซด์ AR')))->toBeTrue();
    expect($labels->contains(fn ($label) => str_contains($label, 'โซเดียมคลอไรด์ AR')))->toBeFalse();
});

test('the item search endpoint matches by item_code too', function () {
    [$student, $ownLab] = studentUserWithLab();
    $item = makeItem(['item_code' => 'CHM-99999']);
    stockItemInLab($item->id, $ownLab->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'CHM-99999']))
        ->assertOk()
        ->assertJsonFragment(['id' => $item->id]);
});

test('an empty query browses the requester\'s own lab stock instead of returning nothing', function () {
    [$student, $ownLab] = studentUserWithLab();
    $item = makeItem();
    stockItemInLab($item->id, $ownLab->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => '']))
        ->assertOk()
        ->assertJsonFragment(['id' => $item->id]);
});

test('a requester with no lab assigned gets nothing back regardless of the query', function () {
    $item = makeItem();
    stockItemInLab($item->id, makeLab()->id);

    $unassigned = studentUser(['lab_id' => null]);
    $this->actingAs($unassigned)->getJson(route('requisitions.items.search', ['q' => $item->name_th]))
        ->assertOk()->assertJson([]);
    $this->actingAs($unassigned)->getJson(route('requisitions.items.search', ['q' => '']))
        ->assertOk()->assertJson([]);
});

test('each result carries the item\'s own base unit id and dimension, for the unit dropdown to match it', function () {
    [$student, $ownLab] = studentUserWithLab();
    $g = Unit::where('code', 'g')->firstOrFail();
    $item = makeItem(['item_code' => 'CHM-88888', 'base_unit_id' => $g->id]);
    stockItemInLab($item->id, $ownLab->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'CHM-88888']))
        ->assertOk()
        ->assertJsonFragment(['baseUnitId' => $item->base_unit_id, 'dimension' => 'MASS']);
});

test('the requisition show page embeds the units list safely inside the x-data attribute', function () {
    // Regression guard for a real bug: @json() alone leaves literal, unescaped quotes in its
    // output (its HEX_* options only escape quote characters *inside string content*, not
    // JSON's own required structural quotes) — that broke the surrounding double-quoted
    // x-data attribute wide open in a real browser, spilling the rest of the JS expression
    // and the <form>'s remaining attributes as visible page text. Js::from() (used in the
    // view now) wraps the payload in JSON.parse('...') with every quote hex-escaped, which
    // is safe. A literal `"id":"` substring in the response means that regressed.
    $student = studentUser();
    $requisition = makeRequisition($student);

    $response = $this->actingAs($student)->get(route('requisitions.show', $requisition));

    $response->assertOk();
    expect($response->getContent())->not->toContain('"id":"');
    $response->assertSee('JSON.parse(', false);
});

test('adding a line item for an item with no stock in the requisition\'s own lab is rejected', function () {
    [$student, $ownLab] = studentUserWithLab();
    $requisition = makeRequisition($student, ['lab_id' => $ownLab->id]);
    $otherLab = makeLab();

    $item = makeItem();
    stockItemInLab($item->id, $otherLab->id);
    $g = Unit::where('code', 'g')->firstOrFail();

    $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id,
        'qty_requested' => '5',
        'unit_id' => $g->id,
    ])->assertSessionHasErrors('item_id');

    expect($requisition->items()->count())->toBe(0);
});

// --- ITM-2610-062: only WORKING stock can be requested --------------------------------------------------------------

test('ITM-2610-062: the item search does not offer an item that exists only in the branch\'s IMS store', function () {
    [$student, $ownLab] = studentUserWithLab();

    $imsOnly = makeItem(['name_th' => 'สารเฉพาะคลังกลาง ZIMS']);
    makeImsLot($ownLab->id, $imsOnly);
    $working = makeItem(['name_th' => 'สารคลังใช้งาน ZIMS']);
    stockItemInLab($working->id, $ownLab->id);
    $both = makeItem(['name_th' => 'สารมีสองที่ ZIMS']);
    makeImsLot($ownLab->id, $both);
    stockItemInLab($both->id, $ownLab->id);

    $ids = collect($this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => 'ZIMS']))->assertOk()->json())->pluck('id');
    expect($ids->contains($working->id))->toBeTrue()
        ->and($ids->contains($both->id))->toBeTrue()
        ->and($ids->contains($imsOnly->id))->toBeFalse();

    // Browsing with an empty query hides it too.
    $browse = collect($this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => '']))->assertOk()->json())->pluck('id');
    expect($browse->contains($imsOnly->id))->toBeFalse()->and($browse->contains($working->id))->toBeTrue();
});

test('ITM-2610-062: adding an IMS-only item to a requisition is refused on the server with the IMS-only message, not the generic one', function () {
    [$student, $ownLab] = studentUserWithLab();
    $requisition = makeRequisition($student, ['lab_id' => $ownLab->id]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $post = fn ($item) => $this->actingAs($student)->post(route('requisitions.items.store', $requisition), [
        'item_id' => $item->id, 'qty_requested' => '5', 'unit_id' => $g->id,
    ]);

    $imsOnly = makeItem();
    makeImsLot($ownLab->id, $imsOnly);
    $post($imsOnly)->assertSessionHasErrors(['item_id' => __('working_stock.error.item_ims_only')]);

    // IMS stock of ANOTHER branch, or no stock anywhere: the generic "not in this lab's stock" message.
    $foreign = makeItem();
    makeImsLot(makeLab()->id, $foreign);
    $post($foreign)->assertSessionHasErrors(['item_id' => __('requisitions.validation.item_not_in_lab_stock')]);
    $nothing = makeItem();
    $post($nothing)->assertSessionHasErrors(['item_id' => __('requisitions.validation.item_not_in_lab_stock')]);

    // A used-up IMS lot is not "IMS stock" either.
    $emptied = makeItem();
    makeImsLot($ownLab->id, $emptied, '1', '500', ['qty_remaining_base' => '0.000000']);
    $post($emptied)->assertSessionHasErrors(['item_id' => __('requisitions.validation.item_not_in_lab_stock')]);

    expect($requisition->items()->count())->toBe(0);

    // With working stock in the branch (even beside IMS stock) the line is accepted.
    $working = makeItem();
    makeImsLot($ownLab->id, $working);
    stockItemInLab($working->id, $ownLab->id);
    $post($working)->assertSessionHasNoErrors();
    expect($requisition->items()->count())->toBe(1);
});
