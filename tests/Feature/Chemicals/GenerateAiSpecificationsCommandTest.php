<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('chemicals:ai-generate-specs command populates empty specifications and records audit log', function () {
    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'สเปกทดสอบโดย AI',
                    ],
                ],
            ],
        ], 200),
    ]);

    $item = makeItem([
        'name_th' => 'เอทานอล 95%',
        'cas_no' => '64-17-5',
        'specification' => null,
        'is_active' => true,
    ]);

    $this->artisan('chemicals:ai-generate-specs', ['--limit' => 1, '--delay' => 0])
        ->assertSuccessful();

    $item->refresh();
    expect($item->specification)->toContain('สเปกทดสอบโดย AI')
        ->and($item->specification)->toContain(\App\Domain\Chemicals\Services\ChemicalSpecificationAiService::SPEC_PREFIX);

    $log = AuditLog::where('action', 'AI_SPEC_GENERATE')
        ->where('entity_id', $item->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->message)->toBe('Generated via KKU GenAI Gateway');
});

test('chemicals:ai-generate-specs command preserves existing PubChem-derived specification instead of overwriting it', function () {
    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'ข้อกำหนดบรรจุภัณฑ์: เก็บในที่แห้ง',
                    ],
                ],
            ],
        ], 200),
    ]);

    $existingPubChemSpec = 'สูตรโมเลกุล C2H6O, น้ำหนักโมเลกุล (MW) 46.07 g/mol, CAS No. 64-17-5 (PubChem CID: 702)';

    $item = makeItem([
        'name_th' => 'เอทานอล',
        'cas_no' => '64-17-5',
        'specification' => $existingPubChemSpec,
        'is_active' => true,
    ]);

    $this->artisan('chemicals:ai-generate-specs', ['--limit' => 1, '--delay' => 0])
        ->assertSuccessful();

    $item->refresh();
    // The real, verified PubChem facts must survive — never silently destroyed by the AI draft.
    expect($item->specification)->toContain($existingPubChemSpec)
        ->and($item->specification)->toContain('ข้อกำหนดบรรจุภัณฑ์: เก็บในที่แห้ง')
        ->and($item->specification)->toContain(\App\Domain\Chemicals\Services\ChemicalSpecificationAiService::SPEC_PREFIX);
});

test('chemicals:ai-generate-specs command replaces its own previous AI block in place on a re-run, not duplicating it', function () {
    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'สเปกฉบับใหม่ล่าสุด',
                    ],
                ],
            ],
        ], 200),
    ]);

    $item = makeItem([
        'name_th' => 'เอทานอล',
        'cas_no' => '64-17-5',
        'specification' => 'สูตรโมเลกุล C2H6O (PubChem CID: 702)'
            ."\n\n".\App\Domain\Chemicals\Services\ChemicalSpecificationAiService::SPEC_PREFIX.'สเปกฉบับเก่า',
        'is_active' => true,
    ]);

    $this->artisan('chemicals:ai-generate-specs', ['--limit' => 1, '--delay' => 0, '--force' => true])
        ->assertSuccessful();

    $item->refresh();
    expect($item->specification)->toContain('สูตรโมเลกุล C2H6O (PubChem CID: 702)')
        ->and($item->specification)->toContain('สเปกฉบับใหม่ล่าสุด')
        ->and($item->specification)->not->toContain('สเปกฉบับเก่า')
        ->and(substr_count($item->specification, '[ร่างโดย AI'))->toBe(1);
});

test('chemicals:ai-generate-specs default scope still skips an item that already has an AI block after other content', function () {
    Http::fake();

    makeItem([
        'name_th' => 'เอทานอล',
        'cas_no' => '64-17-5',
        'specification' => 'สูตรโมเลกุล C2H6O (PubChem CID: 702)'
            ."\n\n".\App\Domain\Chemicals\Services\ChemicalSpecificationAiService::SPEC_PREFIX.'สเปกที่มีอยู่แล้ว',
        'is_active' => true,
    ]);

    $this->artisan('chemicals:ai-generate-specs', ['--delay' => 0])
        ->expectsOutput('ไม่พบรายการสารเคมีที่ต้องสร้างสเปก')
        ->assertSuccessful();

    Http::assertNothingSent();
});

test('chemicals:ai-generate-specs command respects dry-run mode', function () {
    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => 'สเปกทดสอบโดย AI',
                    ],
                ],
            ],
        ], 200),
    ]);

    $item = makeItem([
        'name_th' => 'โซเดียมไฮดรอกไซด์',
        'specification' => null,
        'is_active' => true,
    ]);

    $this->artisan('chemicals:ai-generate-specs', ['--limit' => 1, '--delay' => 0, '--dry-run' => true])
        ->assertSuccessful();

    $item->refresh();
    expect($item->specification)->toBeNull();
});
