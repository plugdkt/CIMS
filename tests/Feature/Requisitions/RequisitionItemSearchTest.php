<?php

use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('studentUserWithLab')) {
    function studentUserWithLab(): array
    {
        $lab = makeLab();
        $student = studentUser(['lab_id' => $lab->id]);

        return [$student, $lab];
    }
}

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

test('the item search endpoint returns nothing for an empty query or a requester with no lab', function () {
    [$student] = studentUserWithLab();
    $item = makeItem();
    stockItemInLab($item->id, makeLab()->id);

    $this->actingAs($student)->getJson(route('requisitions.items.search', ['q' => '']))
        ->assertOk()->assertJson([]);

    $unassigned = studentUser(['lab_id' => null]);
    $this->actingAs($unassigned)->getJson(route('requisitions.items.search', ['q' => $item->name_th]))
        ->assertOk()->assertJson([]);
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
