<?php

use App\Domain\Inventory\Exceptions\MissingDensityException;
use App\Domain\Shared\UnitConverter;
use App\Models\Unit;

// UT-01
test('toBase converts grams to milligrams', function () {
    $converter = new UnitConverter();
    $grams = new Unit(['dimension' => 'MASS', 'factor_to_base' => '1000']);

    expect($converter->toBase('12.5', $grams))->toBe('12500.000000');
});

// UT-02
test('fromBase converts milligrams back to grams', function () {
    $converter = new UnitConverter();
    $grams = new Unit(['dimension' => 'MASS', 'factor_to_base' => '1000']);

    expect($converter->fromBase('487500', $grams))->toBe('487.500000');
});

// UT-03
test('crossDimension without density throws MissingDensityException', function () {
    $converter = new UnitConverter();

    $converter->crossDimension('1000', 'VOLUME', 'MASS', null);
})->throws(MissingDensityException::class);

// UT-04
test('crossDimension converts volume to mass using density', function () {
    $converter = new UnitConverter();

    expect($converter->crossDimension('1000', 'VOLUME', 'MASS', 0.789))->toBe('789.000000');
});
