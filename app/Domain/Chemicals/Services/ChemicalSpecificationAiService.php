<?php

declare(strict_types=1);

namespace App\Domain\Chemicals\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ChemicalSpecificationAiService
{
    private const CACHE_TTL_DAYS = 30;

    public const SPEC_PREFIX = "[ร่างโดย AI — โปรดตรวจสอบความถูกต้องและระบุเกรดที่ต้องการก่อนนำไปใช้จัดซื้อจริง]\n\n";

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds = 30,
        private readonly string|bool|null $caBundle = null,
    ) {
    }

    /**
     * Generate laboratory chemical specifications using GenAI.
     * Caches successful responses for 30 days. Returns null gracefully on any failure (never caches null).
     */
    public function generateSpecification(
        string $nameTh,
        ?string $nameEn = null,
        ?string $casNo = null,
        ?string $formula = null,
    ): ?string {
        $nameTh = trim($nameTh);
        $nameEn = $nameEn !== null ? trim($nameEn) : null;
        $casNo = $casNo !== null ? trim($casNo) : null;
        $formula = $formula !== null ? trim($formula) : null;

        if ($nameTh === '' && ($nameEn === null || $nameEn === '') && ($casNo === null || $casNo === '')) {
            return null;
        }

        if ($this->apiKey === null || trim($this->apiKey) === '') {
            Log::warning('ChemicalSpecificationAiService: API key is not configured.');

            return null;
        }

        $cacheKey = 'ai_spec:v2:'.hash('xxh128', "{$nameTh}|{$nameEn}|{$casNo}|{$formula}|{$this->model}");
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $systemPrompt = 'คุณคือผู้เชี่ยวชาญด้านเคมีและพัสดุวิทยาศาสตร์ประจำห้องปฏิบัติการ '
            .'จงสร้าง "คุณลักษณะเฉพาะ / สเปกจัดซื้อจัดจ้าง (Specifications)" สำหรับสารเคมีที่ระบุ '
            .'โดยเขียนเป็นภาษาไทยที่เป็นทางการ กระชับ เป็นข้อๆ ครอบคลุม: '
            .'1. สถานะทางกายภาพและลักษณะปรากฏ '
            .'2. เกรดมาตรฐานทั่วไปที่มักมีจำหน่าย (เช่น เกรด AR/ACS/Technical/HPLC grade) '
            .'โดยให้ระบุว่า "โดยทั่วไปมีจำหน่ายในเกรด AR/ACS/Technical ผู้จัดซื้อควรระบุเกรดและความบริสุทธิ์ตามวัตถุประสงค์การใช้งานจริง" '
            .'ห้ามคาดเดาหรือฟันธงตัวเลขเปอร์เซ็นต์ความบริสุทธิ์เฉพาะเจาะจงของสารเองหากไม่ได้ระบุมา '
            .'3. ข้อกำหนดบรรจุภัณฑ์และการจัดเก็บ '
            .'ไม่ต้องใส่คำเกริ่นหรือคำลงท้าย';

        $promptParts = [];
        if ($nameTh !== '') {
            $promptParts[] = "ชื่อสารเคมี (TH): {$nameTh}";
        }
        if ($nameEn !== null && $nameEn !== '') {
            $promptParts[] = "ชื่อสารเคมี (EN): {$nameEn}";
        }
        if ($formula !== null && $formula !== '') {
            $promptParts[] = "สูตรโมเลกุล: {$formula}";
        }
        if ($casNo !== null && $casNo !== '') {
            $promptParts[] = "CAS Number: {$casNo}";
        }

        $userPrompt = implode("\n", $promptParts);

        try {
            $url = rtrim($this->baseUrl, '/').'/chat/completions';

            $client = Http::timeout($this->timeoutSeconds);
            if ($this->caBundle !== null) {
                $client = $client->withOptions(['verify' => $this->caBundle]);
            }

            $response = $client->withToken($this->apiKey)
                ->asJson()
                ->post($url, [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 600,
                ]);

            if (! $response->successful()) {
                Log::warning('ChemicalSpecificationAiService: API returned error status', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            /** @var array{choices?: list<array{message?: array{content?: string}}>} $json */
            $json = $response->json();
            $content = $json['choices'][0]['message']['content'] ?? null;

            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $trimmedContent = trim($content);
            $result = str_starts_with($trimmedContent, '[ร่างโดย AI')
                ? $trimmedContent
                : self::SPEC_PREFIX.$trimmedContent;

            Cache::put($cacheKey, $result, now()->addDays(self::CACHE_TTL_DAYS));

            return $result;
        } catch (Throwable $e) {
            Log::warning('ChemicalSpecificationAiService: request failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
