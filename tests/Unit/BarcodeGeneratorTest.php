<?php

use App\Domain\Labeling\Services\BarcodeGenerator;

test('svg produces valid SVG markup', function () {
    $svg = (new BarcodeGenerator())->svg('GRN-2569-00001-01-001');

    expect($svg)->toContain('<svg');
});

test('svg has no XML prolog, so it is safe to embed directly inline in HTML', function () {
    $svg = (new BarcodeGenerator())->svg('GRN-2569-00001-01-001');

    expect($svg)->toStartWith('<svg');
    expect($svg)->not->toContain('<?xml');
});

test('different codes produce different barcodes', function () {
    $generator = new BarcodeGenerator();

    expect($generator->svg('CODE-AAA'))->not->toBe($generator->svg('CODE-BBB'));
});
