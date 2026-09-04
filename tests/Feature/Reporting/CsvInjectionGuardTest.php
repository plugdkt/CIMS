<?php

use App\Domain\Reporting\Services\CsvInjectionGuard;

test('SEC-IN-10: a value starting with = + - @ gets a leading apostrophe prefix', function (string $input, string $expected) {
    expect(CsvInjectionGuard::sanitize($input))->toBe($expected);
})->with([
    ['=SUM(A1:A2)', "'=SUM(A1:A2)"],
    ['+1234', "'+1234"],
    ['-1234', "'-1234"],
    ['@SUM(1+1)', "'@SUM(1+1)"],
]);

test('an ordinary value is left untouched', function () {
    expect(CsvInjectionGuard::sanitize('สมชาย ใจดี'))->toBe('สมชาย ใจดี');
    expect(CsvInjectionGuard::sanitize('น้ำยาทำความสะอาด'))->toBe('น้ำยาทำความสะอาด');
});

test('an empty string is left untouched', function () {
    expect(CsvInjectionGuard::sanitize(''))->toBe('');
});
