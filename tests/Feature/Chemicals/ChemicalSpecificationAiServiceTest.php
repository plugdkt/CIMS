<?php

declare(strict_types=1);

use App\Domain\Chemicals\Services\ChemicalSpecificationAiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('ChemicalSpecificationAiService generates specification with prefix on successful API response', function () {
    Cache::flush();

    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "คุณลักษณะเฉพาะ:\n1. ของเหลวใส\n2. โดยทั่วไปมีจำหน่ายในเกรด AR/ACS/Technical ผู้จัดซื้อควรระบุเกรดตามการใช้งานจริง",
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new ChemicalSpecificationAiService(
        baseUrl: 'https://fake.gen.ai/v1',
        apiKey: 'fake-api-key',
        model: 'gemini-2.5-flash-lite',
    );

    $result = $service->generateSpecification(
        nameTh: 'เอทานอล',
        nameEn: 'Ethanol',
        casNo: '64-17-5',
        formula: 'C2H6O',
    );

    expect($result)->toContain('[ร่างโดย AI — โปรดตรวจสอบความถูกต้องและระบุเกรดที่ต้องการก่อนนำไปใช้จัดซื้อจริง]')
        ->and($result)->toContain('โดยทั่วไปมีจำหน่ายในเกรด AR/ACS/Technical');
});

test('ChemicalSpecificationAiService generates medical solution specification with pharmacopoeia standards', function () {
    Cache::flush();

    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "1. องค์ประกอบ: สารละลายโซเดียมคลอไรด์ความเข้มข้น 0.9% w/v\n2. มาตรฐาน: USP/BP ปราศจากเชื้อ (Sterile) และปราศจากไพรโรเจน\n3. บรรจุภัณฑ์: ถุงหรือขวดทางการแพทย์ปิดสนิท ระบุ Lot และ Expiry",
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = new ChemicalSpecificationAiService(
        baseUrl: 'https://fake.gen.ai/v1',
        apiKey: 'fake-api-key',
        model: 'gemini-2.5-flash-lite',
    );

    $result = $service->generateSpecification(
        nameTh: '0.9% Normal Saline (NSS)',
        formula: 'NaCl in H2O',
    );

    expect($result)->toContain('[ร่างโดย AI — โปรดตรวจสอบความถูกต้องและระบุเกรดที่ต้องการก่อนนำไปใช้จัดซื้อจริง]')
        ->and($result)->toContain('USP/BP')
        ->and($result)->toContain('0.9% w/v')
        ->and($result)->toContain('Sterile');
});

test('ChemicalSpecificationAiService returns cached result on subsequent calls', function () {
    Cache::flush();

    $callCount = 0;
    Http::fake([
        '*chat/completions*' => function () use (&$callCount) {
            $callCount++;

            return Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'สเปกแคช',
                        ],
                    ],
                ],
            ], 200);
        },
    ]);

    $service = new ChemicalSpecificationAiService(
        baseUrl: 'https://fake.gen.ai/v1',
        apiKey: 'fake-api-key',
        model: 'gemini-2.5-flash-lite',
    );

    $res1 = $service->generateSpecification('สารเคมี A');
    $res2 = $service->generateSpecification('สารเคมี A');

    expect($res1)->toContain('สเปกแคช')
        ->and($res2)->toContain('สเปกแคช')
        ->and($callCount)->toBe(1);
});

test('ChemicalSpecificationAiService returns null on API error gracefully and does not cache failure', function () {
    Cache::flush();

    Http::fake([
        '*chat/completions*' => Http::response('Gateway Timeout', 504),
    ]);

    $service = new ChemicalSpecificationAiService(
        baseUrl: 'https://fake.gen.ai/v1',
        apiKey: 'fake-api-key',
        model: 'gemini-2.5-flash-lite',
    );

    $result = $service->generateSpecification('สารเคมี B');

    expect($result)->toBeNull();

    // Verify cache is empty for this key
    $cacheKey = 'ai_spec:v3:'.hash('xxh128', 'สารเคมี B||||gemini-2.5-flash-lite');
    expect(Cache::get($cacheKey))->toBeNull();
});

test('ChemicalSpecificationAiService returns null if API key is null or empty', function () {
    $service = new ChemicalSpecificationAiService(
        baseUrl: 'https://fake.gen.ai/v1',
        apiKey: null,
        model: 'gemini-2.5-flash-lite',
    );

    $result = $service->generateSpecification('สารเคมี C');

    expect($result)->toBeNull();
});

test('ChemicalSpecificationAiService returns null if inputs are all empty', function () {
    $service = new ChemicalSpecificationAiService(
        baseUrl: 'https://fake.gen.ai/v1',
        apiKey: 'fake-key',
        model: 'gemini-2.5-flash-lite',
    );

    $result = $service->generateSpecification('', '', '', '');

    expect($result)->toBeNull();
});
