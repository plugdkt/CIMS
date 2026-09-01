<?php

use App\Domain\Locations\Services\LocationIncompatibilityChecker;

test('BR-10 conflicting pairs are detected regardless of order', function () {
    $checker = new LocationIncompatibilityChecker();

    expect($checker->conflicts('ACID', 'BASE'))->toBeTrue();
    expect($checker->conflicts('BASE', 'ACID'))->toBeTrue();
    expect($checker->conflicts('FLAMMABLE', 'OXIDIZER'))->toBeTrue();
    expect($checker->conflicts('OXIDIZER', 'FLAMMABLE'))->toBeTrue();
    expect($checker->conflicts('TOXIC', 'FOOD_GRADE'))->toBeTrue();
    expect($checker->conflicts('FOOD_GRADE', 'TOXIC'))->toBeTrue();
});

test('non-conflicting or unrelated storage classes do not conflict', function () {
    $checker = new LocationIncompatibilityChecker();

    expect($checker->conflicts('ACID', 'TOXIC'))->toBeFalse();
    expect($checker->conflicts('GENERAL', 'ACID'))->toBeFalse();
    expect($checker->conflicts('ACID', 'ACID'))->toBeFalse();
});

test('a null storage class never conflicts', function () {
    $checker = new LocationIncompatibilityChecker();

    expect($checker->conflicts(null, 'ACID'))->toBeFalse();
    expect($checker->conflicts('ACID', null))->toBeFalse();
    expect($checker->conflicts(null, null))->toBeFalse();
});

test('conflictsWithAny returns the distinct conflicting classes found', function () {
    $checker = new LocationIncompatibilityChecker();

    $found = $checker->conflictsWithAny('ACID', ['BASE', 'TOXIC', 'BASE', null]);

    expect($found)->toBe(['BASE']);
});
