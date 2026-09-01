<?php

use App\Domain\Shared\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// UT-06
test('running number resets to 00001 in a new fiscal year', function () {
    $generator = new DocumentNumberGenerator();

    $lastOfOldYear = Carbon\Carbon::parse('2026-09-30');
    expect($generator->next('REQ', $lastOfOldYear))->toBe('REQ-2569-00001');
    expect($generator->next('REQ', $lastOfOldYear))->toBe('REQ-2569-00002');

    $firstOfNewFiscalYear = Carbon\Carbon::parse('2026-10-01');
    expect($generator->next('REQ', $firstOfNewFiscalYear))->toBe('REQ-2570-00001');
});

test('separate prefixes get independent counters', function () {
    $generator = new DocumentNumberGenerator();
    $date = Carbon\Carbon::parse('2026-08-31');

    expect($generator->next('REQ', $date))->toBe('REQ-2569-00001');
    expect($generator->next('GRN', $date))->toBe('GRN-2569-00001');
    expect($generator->next('REQ', $date))->toBe('REQ-2569-00002');
});
