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
