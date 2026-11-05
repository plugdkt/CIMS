<?php

use App\Domain\Shared\ItemType;
use App\Models\Item;
use App\Models\ItemCategory;

/*
 * CEO 2026-11-05 (ITM-2610-063): ItemType has a third value, `science` (loan-and-return science materials).
 * all() = every kind of STOCK (3); requisitionKinds() = the kinds a requisition / shop basket can hold (2).
 * Pure tests: no database (a category is built in memory).
 */

test('all() lists the three kinds of stock; requisitionKinds() the two that can be requisitioned', function () {
    expect(ItemType::all())->toBe(['chemical', 'consumable', 'science'])
        ->and(ItemType::requisitionKinds())->toBe(['chemical', 'consumable'])
        ->and(ItemType::SCIENCE)->toBe('science');
});

test('isValid accepts any kind of stock, isRequisitionKind refuses science and anything else', function () {
    foreach (['chemical', 'consumable', 'science'] as $slug) {
        expect(ItemType::isValid($slug))->toBeTrue($slug);
    }
    foreach (['chemical', 'consumable'] as $slug) {
        expect(ItemType::isRequisitionKind($slug))->toBeTrue($slug);
    }
    foreach (['science', 'SCIENCE', 'Chemical', '', null, 'both', "chemical' OR 1=1"] as $bad) {
        expect(ItemType::isRequisitionKind($bad))->toBeFalse((string) $bad);
    }
    foreach (['SCIENCE', '', null, 'loan'] as $bad) {
        expect(ItemType::isValid($bad))->toBeFalse((string) $bad);
    }
});

test('label() gives the Thai name of every kind (never the raw translation key)', function () {
    foreach (ItemType::all() as $slug) {
        expect(ItemType::label($slug))->not->toBe('items.kind_'.$slug)->not->toBe('');
    }
    expect(ItemType::label('science'))->toBe('วัสดุวิทยาศาสตร์ (ยืม-คืน)');
});

test('slugForCategory: chemical flag wins, only the LOAN_SCIENCE non-chemical category is science, a missing category counts as chemical', function () {
    expect(ItemType::slugForCategory(true, 'CHEMICAL'))->toBe('chemical')
        ->and(ItemType::slugForCategory(true, ItemCategory::LOAN_CODE))->toBe('chemical')   // is_chemical wins over the code
        ->and(ItemType::slugForCategory(false, 'MATERIAL'))->toBe('consumable')
        ->and(ItemType::slugForCategory(false, null))->toBe('consumable')
        ->and(ItemType::slugForCategory(false, ItemCategory::LOAN_CODE))->toBe('science')
        ->and(ItemType::slugForCategory(null, null))->toBe('chemical')
        ->and(ItemType::slugForCategory(null, ItemCategory::LOAN_CODE))->toBe('chemical');
    expect(ItemCategory::LOAN_CODE)->toBe('LOAN_SCIENCE');
});

test('forItem derives the kind from the category in memory, and a missing category counts as chemical', function () {
    $make = function (?ItemCategory $category): Item {
        $item = new Item();
        $item->setRelation('category', $category);

        return $item;
    };

    expect(ItemType::forItem($make(new ItemCategory(['code' => 'CHEMICAL', 'name_th' => 'x', 'is_chemical' => true]))))->toBe('chemical')
        ->and(ItemType::forItem($make(new ItemCategory(['code' => 'MATERIAL', 'name_th' => 'x', 'is_chemical' => false]))))->toBe('consumable')
        ->and(ItemType::forItem($make(new ItemCategory(['code' => 'LOAN_SCIENCE', 'name_th' => 'x', 'is_chemical' => false]))))->toBe('science')
        ->and(ItemType::forItem($make(null)))->toBe('chemical')
        ->and($make(new ItemCategory(['code' => 'LOAN_SCIENCE', 'name_th' => 'x', 'is_chemical' => false]))->kind())->toBe('science');
});

test('ItemCategory::kind() agrees with the single rule in ItemType', function () {
    expect((new ItemCategory(['code' => 'DYE', 'name_th' => 'x', 'is_chemical' => true]))->kind())->toBe('chemical')
        ->and((new ItemCategory(['code' => 'GLASSWARE', 'name_th' => 'x', 'is_chemical' => false]))->kind())->toBe('consumable')
        ->and((new ItemCategory(['code' => ItemCategory::LOAN_CODE, 'name_th' => 'x', 'is_chemical' => false]))->kind())->toBe('science');
});

test('the old two-way helpers never yield science: slugFor and requestTypeValue', function () {
    expect(ItemType::slugFor(true))->toBe('chemical')
        ->and(ItemType::slugFor(false))->toBe('consumable')
        ->and(ItemType::requestTypeValue('chemical'))->toBe('CHEMICAL')
        ->and(ItemType::requestTypeValue('consumable'))->toBe('CONSUMABLE')
        ->and(ItemType::cartKey('chemical'))->toBe('shop.cart.chemical');
});
