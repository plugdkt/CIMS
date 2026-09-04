<?php

use App\Domain\Requisition\Exceptions\InvalidSignatureImageException;
use App\Domain\Requisition\Services\SignatureImageService;
use Illuminate\Support\Facades\Storage;

const SIG_TEST_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

test('a valid PNG data URL is stored and hashed', function () {
    Storage::fake('signatures');

    $result = app(SignatureImageService::class)->store(SIG_TEST_PNG);

    expect($result['path'])->toEndWith('.png');
    expect($result['hash'])->toHaveLength(64);
    Storage::disk('signatures')->assertExists($result['path']);
});

test('a non-PNG data URL is rejected', function () {
    Storage::fake('signatures');

    expect(fn () => app(SignatureImageService::class)->store('data:text/plain;base64,aGVsbG8='))
        ->toThrow(InvalidSignatureImageException::class);
});

test('a string that is not a data URL at all is rejected', function () {
    Storage::fake('signatures');

    expect(fn () => app(SignatureImageService::class)->store('not-a-data-url'))
        ->toThrow(InvalidSignatureImageException::class);
});

test('garbage base64 that decodes but is not a real PNG is rejected', function () {
    Storage::fake('signatures');

    expect(fn () => app(SignatureImageService::class)->store('data:image/png;base64,aGVsbG8gd29ybGQ='))
        ->toThrow(InvalidSignatureImageException::class);
});
