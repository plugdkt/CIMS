<?php

use App\Domain\Labeling\Services\QrCodeGenerator;

test('svg produces valid SVG markup with no XML prolog', function () {
    $svg = (new QrCodeGenerator())->svg('https://example.test/verify/01ABC');

    expect($svg)->toStartWith('<svg');
    expect($svg)->not->toContain('<?xml');
});

test('different payloads produce different QR codes', function () {
    $generator = new QrCodeGenerator();

    expect($generator->svg('payload-a'))->not->toBe($generator->svg('payload-b'));
});
