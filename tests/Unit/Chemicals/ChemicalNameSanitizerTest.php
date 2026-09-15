<?php

use App\Domain\Chemicals\Services\ChemicalNameSanitizer;

test('ChemicalNameSanitizer strips concentration percentages from chemical names', function () {
    $sanitizer = new ChemicalNameSanitizer();

    expect($sanitizer->sanitize('Ethanol 95%'))->toBe('Ethanol');
    expect($sanitizer->sanitize('Ethanol 99.9% v/v'))->toBe('Ethanol');
    expect($sanitizer->sanitize('70% Ethanol'))->toBe('Ethanol');
    expect($sanitizer->sanitize('Alcohol 95% (V/V)'))->toBe('Alcohol');
});

test('ChemicalNameSanitizer strips Thai text, grades, and packaging notes', function () {
    $sanitizer = new ChemicalNameSanitizer();

    expect($sanitizer->sanitize('Ethanol 95% COM องค์การสุรา'))->toBe('Ethanol');
    expect($sanitizer->sanitize('Methanol (HDPE)'))->toBe('Methanol');
    expect($sanitizer->sanitize('Ethanol 99% grade'))->toBe('Ethanol');
    expect($sanitizer->sanitize('Ethanol absolute C2H5OH Liquid'))->toBe('Ethanol absolute C2H5OH');
});

test('ChemicalNameSanitizer extracts CAS numbers accurately', function () {
    $sanitizer = new ChemicalNameSanitizer();

    expect($sanitizer->extractCas('CAS 64-17-5'))->toBe('64-17-5');
    expect($sanitizer->extractCas('2-Methoxyethanol 109-86-4 liquid'))->toBe('109-86-4');
    expect($sanitizer->extractCas('Ethanol without CAS'))->toBeNull();
});
