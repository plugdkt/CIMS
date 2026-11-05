<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use Database\Seeders\ItemCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * CEO 2026-11-05 (ITM-2610-063): the third kind of stock, "science" (loan-and-return), has no column of its own: it is
 * the dedicated category LOAN_SCIENCE (is_chemical = false) added by ItemCategorySeeder. These tests pin the data
 * step (idempotent, leaves every other category alone) and the query scopes every other feature relies on.
 */

// --- seeder -------------------------------------------------------------------------------------------------------

test('the seeder adds LOAN_SCIENCE "วัสดุวิทยาศาสตร์ (ยืม-คืน)" as a non-chemical category', function () {
    $loan = ItemCategory::where('code', ItemCategory::LOAN_CODE)->firstOrFail();

    expect($loan->name_th)->toBe('วัสดุวิทยาศาสตร์ (ยืม-คืน)')
        ->and($loan->is_chemical)->toBeFalse()
        ->and($loan->kind())->toBe('science');
});

test('running the seeder again is idempotent: same rows, same ids, no duplicate LOAN_SCIENCE', function () {
    $before = ItemCategory::orderBy('id')->get(['id', 'code', 'name_th', 'is_chemical'])->toArray();

    $this->seed(ItemCategorySeeder::class);
    $this->seed(ItemCategorySeeder::class);

    expect(ItemCategory::where('code', ItemCategory::LOAN_CODE)->count())->toBe(1)
        ->and(ItemCategory::orderBy('id')->get(['id', 'code', 'name_th', 'is_chemical'])->toArray())->toBe($before);
});

test('deploy step: on a database that has no LOAN_SCIENCE yet, the seeder adds just that row and leaves every existing category and item alone', function () {
    $item = makeItem(['name_th' => 'วัสดุเดิมก่อน deploy']);
    $custom = ItemCategory::create(['code' => 'CUSTOM_X', 'name_th' => 'หมวดที่ผู้ดูแลเพิ่มเอง', 'is_chemical' => false]);
    ItemCategory::where('code', ItemCategory::LOAN_CODE)->delete();   // the pre-deploy state

    $others = ItemCategory::orderBy('id')->get(['id', 'code', 'name_th', 'is_chemical'])->toArray();
    expect(ItemCategory::where('code', ItemCategory::LOAN_CODE)->exists())->toBeFalse();

    $this->seed(ItemCategorySeeder::class);

    expect(ItemCategory::where('code', ItemCategory::LOAN_CODE)->count())->toBe(1)
        ->and(ItemCategory::where('code', '!=', ItemCategory::LOAN_CODE)->orderBy('id')->get(['id', 'code', 'name_th', 'is_chemical'])->toArray())->toBe($others)
        ->and($custom->fresh()->name_th)->toBe('หมวดที่ผู้ดูแลเพิ่มเอง')
        ->and($item->fresh()->category_id)->toBe($item->category_id)
        ->and($item->fresh()->kind())->toBe('chemical');
});

test('a category an admin added by hand (non-chemical, any other code) is consumable, never science', function () {
    $custom = ItemCategory::create(['code' => 'CUSTOM_Y', 'name_th' => 'หมวดเพิ่มเอง', 'is_chemical' => false]);

    expect($custom->kind())->toBe('consumable')
        ->and(ItemCategory::ofKind('science')->pluck('code')->all())->toBe([ItemCategory::LOAN_CODE]);
});

// --- ItemCategory scopes ----------------------------------------------------------------------------------------------

test('ItemCategory::ofKind splits every category into exactly one kind, and the three lists cover all categories', function () {
    $chemical = ItemCategory::ofKind('chemical')->pluck('code')->all();
    $consumable = ItemCategory::ofKind('consumable')->pluck('code')->all();
    $science = ItemCategory::ofKind('science')->pluck('code')->all();

    expect($science)->toBe(['LOAN_SCIENCE'])
        ->and($chemical)->toContain('CHEMICAL', 'MEDIA', 'DYE', 'OTHER_CHEM')->not->toContain('MATERIAL')->not->toContain('LOAN_SCIENCE')
        ->and($consumable)->toContain('MATERIAL', 'CONSUMABLE', 'GLASSWARE')->not->toContain('LOAN_SCIENCE')->not->toContain('CHEMICAL')
        ->and(array_intersect($chemical, $consumable))->toBe([])
        ->and(count($chemical) + count($consumable) + count($science))->toBe(ItemCategory::count());
});

test('ItemCategory::requisitionable keeps chemical and consumable categories and drops only LOAN_SCIENCE', function () {
    $codes = ItemCategory::requisitionable()->pluck('code')->all();

    expect($codes)->toContain('CHEMICAL', 'MATERIAL', 'GLASSWARE')->not->toContain('LOAN_SCIENCE')
        ->and(count($codes))->toBe(ItemCategory::count() - 1);
});

// --- Item scopes ---------------------------------------------------------------------------------------------------------

test('Item::ofKind / requisitionable / kind() follow the category', function () {
    $chem = makeItem();
    $supply = consumableItem();
    $science = scienceItem();

    expect(Item::ofKind('chemical')->pluck('id')->all())->toBe([$chem->id])
        ->and(Item::ofKind('consumable')->pluck('id')->all())->toBe([$supply->id])
        ->and(Item::ofKind('science')->pluck('id')->all())->toBe([$science->id])
        ->and(Item::requisitionable()->pluck('id')->sort()->values()->all())->toBe([$chem->id, $supply->id])
        ->and($chem->kind())->toBe('chemical')
        ->and($supply->kind())->toBe('consumable')
        ->and($science->kind())->toBe('science');
});

test('re-categorising an item moves it between the kinds at once (the kind is never stored on the item)', function () {
    $item = makeItem();
    expect($item->kind())->toBe('chemical');

    $item->update(['category_id' => categoryId(ItemCategory::LOAN_CODE)]);

    expect($item->fresh()->kind())->toBe('science')
        ->and(Item::requisitionable()->whereKey($item->id)->exists())->toBeFalse();
});

// --- Requisition scopes ----------------------------------------------------------------------------------------------------

test('Requisition::itemKinds reports "science" for a finished document whose item was re-categorised later, and does not throw', function () {
    $requisition = requisitionWithLines(staffUser(), ['CHEMICAL'], ['status' => 'ISSUED']);
    $line = $requisition->items()->firstOrFail();
    $line->item->update(['category_id' => categoryId(ItemCategory::LOAN_CODE)]);

    expect($requisition->fresh()->itemKinds())->toBe(['science']);
});

test('Requisition::itemKinds lists kinds in a fixed order (chemical, consumable, science) for a document that mixes them', function () {
    $requisition = requisitionWithLines(staffUser(), ['GLASSWARE', 'LOAN_SCIENCE', 'DYE'], ['status' => 'ISSUED']);

    expect($requisition->itemKinds())->toBe(['chemical', 'consumable', 'science']);
});

test('Requisition::itemKinds with no lines falls back to request_type and never answers science', function () {
    $empty = makeRequisition(staffUser(), ['request_type' => ['CONSUMABLE']]);

    expect($empty->itemKinds())->toBe(['consumable']);
});

test('Requisition::ofItemType and ofAnyItemType find a document by its science line; a plain chemical document is not science', function () {
    $staff = staffUser();
    $sci = requisitionWithLines($staff, ['LOAN_SCIENCE'], ['status' => 'ISSUED']);
    $chem = requisitionWithLines($staff, ['CHEMICAL'], ['status' => 'SUBMITTED']);
    $noLines = makeRequisition($staff, ['request_type' => ['CHEMICAL']]);

    expect(Requisition::ofItemType('science')->pluck('id')->all())->toBe([$sci->id])
        ->and(Requisition::ofAnyItemType(['science'])->pluck('id')->all())->toBe([$sci->id])
        ->and(Requisition::ofAnyItemType(['chemical'])->pluck('id')->sort()->values()->all())->toBe([$chem->id, $noLines->id])
        ->and(Requisition::ofAnyItemType([])->count())->toBe(0);
});

test('Requisition::open() is everything except ISSUED / REJECTED / CANCELLED (DRAFT counts as open)', function () {
    $staff = staffUser();
    $statuses = ['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'PARTIALLY_ISSUED', 'ISSUED', 'REJECTED', 'CANCELLED'];
    $byStatus = [];
    foreach ($statuses as $status) {
        $byStatus[$status] = makeRequisition($staff, ['status' => $status])->id;
    }

    $open = Requisition::open()->pluck('id')->all();

    foreach (['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'PARTIALLY_ISSUED'] as $status) {
        expect($open)->toContain($byStatus[$status]);
    }
    foreach (['ISSUED', 'REJECTED', 'CANCELLED'] as $status) {
        expect($open)->not->toContain($byStatus[$status]);
    }
});
