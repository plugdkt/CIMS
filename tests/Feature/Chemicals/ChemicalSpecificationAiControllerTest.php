<?php

declare(strict_types=1);

use App\Domain\Chemicals\Services\ChemicalSpecificationAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('guest gets 401 on ai-specification endpoint', function () {
    $this->postJson(route('items.ai-specification'), [
        'name_th' => 'เอทานอล',
    ])->assertUnauthorized();
});

test('user with item.view only (SCIENTIST) gets 403 on ai-specification endpoint', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)
        ->postJson(route('items.ai-specification'), [
            'name_th' => 'เอทานอล',
        ])
        ->assertForbidden();
});

test('user with item.manage (LAB_MANAGER) successfully receives generated specification with AI prefix', function () {
    Http::fake([
        '*chat/completions*' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => "1. ลักษณะทางกายภาพ: ของเหลวใส\n2. โดยทั่วไปมีจำหน่ายในเกรด AR/ACS/Technical ผู้จัดซื้อควรระบุเกรดตามการใช้งานจริง",
                    ],
                ],
            ],
        ], 200),
    ]);

    $manager = labManagerUser();

    $response = $this->actingAs($manager)
        ->postJson(route('items.ai-specification'), [
            'name_th' => 'เอทานอล',
            'name_en' => 'Ethanol',
            'cas_no' => '64-17-5',
            'formula' => 'C2H6O',
        ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    $data = $response->json();
    expect($data['specification'])->toContain(ChemicalSpecificationAiService::SPEC_PREFIX)
        ->and($data['specification'])->toContain('โดยทั่วไปมีจำหน่ายในเกรด AR/ACS/Technical');
});

test('ai-specification endpoint validates that at least one identifier is present', function () {
    $manager = labManagerUser();

    $this->actingAs($manager)
        ->postJson(route('items.ai-specification'), [
            'name_th' => '',
            'name_en' => '',
            'cas_no' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name_th']);
});

test('ai-specification endpoint returns 503 when AI service fails', function () {
    Http::fake([
        '*chat/completions*' => Http::response('Server error', 500),
    ]);

    $manager = labManagerUser();

    $this->actingAs($manager)
        ->postJson(route('items.ai-specification'), [
            'name_th' => 'เอทานอล',
        ])
        ->assertStatus(503)
        ->assertJson([
            'success' => false,
        ]);
});
