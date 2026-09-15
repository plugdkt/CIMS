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
     * Differentiates between raw chemicals/reagents vs finished medical products/solutions.
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

        $cacheKey = 'ai_spec:v3:'.hash('xxh128', "{$nameTh}|{$nameEn}|{$casNo}|{$formula}|{$this->model}");
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $systemPrompt = <<<PROMPT
คุณคือผู้เชี่ยวชาญด้านเคมี เภสัชภัณฑ์ และพัสดุวิทยาศาสตร์ประจำห้องปฏิบัติการ
จงร่าง "คุณลักษณะเฉพาะ / สเปกจัดซื้อจัดจ้าง (Specifications)" สำหรับรายการที่ระบุ โดยเขียนเป็นภาษาไทยที่เป็นทางการ กระชับ และเป็นข้อๆ

หลักการจำแนกประเภทสารเพื่อระบุข้อกำหนด:
1. กรณีเป็น "สารเคมีตั้งต้น / สารบริสุทธิ์ / รีเอเจนต์วิเคราะห์" (Raw Chemical / Analytical Reagent):
   - สถานะทางกายภาพ: ลักษณะ สี สภาพการละลาย
   - เกรด/มาตรฐาน: ระบุเกรดทั่วไปที่มีจำหน่าย เช่น AR / ACS / Analytical / HPLC / Technical Grade โดยระบุว่า "ผู้จัดซื้อควรระบุเกรดและความบริสุทธิ์ตามวัตถุประสงค์การใช้งานจริง" (ห้ามแต่งตัวเลขเปอร์เซ็นต์ความบริสุทธิ์เฉพาะเจาะจงเองหากไม่มีข้อมูล)
   - บรรจุภัณฑ์และการจัดเก็บ: ภาชนะบรรจุที่เหมาะสม มีเอกสารรับรองการวิเคราะห์ (CoA) และเอกสารข้อมูลความปลอดภัย (SDS) กำกับ
2. กรณีเป็น "สารละลายสำเร็จรูป / ผลิตภัณฑ์ทางการแพทย์ / เภสัชภัณฑ์ / น้ำยาฆ่าเชื้อ" (Formulated Product / Medical Solution / Disinfectant):
   - องค์ประกอบและความเข้มข้น: ระบุความเข้มข้นและองค์ประกอบหลักตามที่ปรากฏในชื่อรายการ
   - มาตรฐานคุณภาพ: อ้างอิงเภสัชตำรับ (เช่น USP / BP) หรือมาตรฐานทางการแพทย์ (เช่น ปราศจากเชื้อ/Sterile, ปราศจากไพรโรเจน/Pyrogen-free, มีทะเบียน อย. หรือมาตรฐานเครื่องมือแพทย์) หรือเกรดน้ำยาสำเร็จรูปสำหรับห้องปฏิบัติการ
   - บรรจุภัณฑ์และการจัดเก็บ: ภาชนะปิดสนิท ฉลากระบุ Lot/Batch Number วันผลิตและวันหมดอายุชัดเจน

ตัวอย่างที่ 1 (สารเคมีตั้งต้น):
ชื่อ: Sodium Chloride (เกลือแกงบริสุทธิ์)
สูตร: NaCl, CAS: 7647-14-5
ผลลัพธ์:
1. สถานะทางกายภาพและลักษณะปรากฏ:
   - ของแข็ง เป็นผลึกหรือผงสีขาว ไม่มีกลิ่น ละลายได้ดีในน้ำ
2. เกรดและมาตรฐานอ้างอิง:
   - โดยทั่วไปมีจำหน่ายในเกรด AR (Analytical Reagent), ACS หรือ Technical grade
   - ผู้จัดซื้อควรระบุเกรดและความบริสุทธิ์ที่ต้องการตามวัตถุประสงค์การใช้งานจริงในห้องปฏิบัติการ
   - มีเอกสารรับรองผลการวิเคราะห์ (CoA) และเอกสารข้อมูลความปลอดภัย (SDS)
3. ข้อกำหนดบรรจุภัณฑ์และการจัดเก็บ:
   - บรรจุในขวดพลาสติก HDPE หรือแก้ว ปิดสนิทป้องกันความชื้น
   - เก็บในที่แห้ง อุณหภูมิห้อง อากาศถ่ายเทสะดวก

ตัวอย่างที่ 2 (ผลิตภัณฑ์ทางการแพทย์ / สารละลายสำเร็จรูป):
ชื่อ: 0.9% Normal Saline (NSS)
สูตร: NaCl in H2O
ผลลัพธ์:
1. องค์ประกอบและลักษณะทางกายภาพ:
   - สารละลายโซเดียมคลอไรด์ความเข้มข้น 0.9% w/v (Isotonic Sodium Chloride Solution)
   - ของเหลวใส ไม่มีสี ไม่มีตะกอน
2. มาตรฐานคุณภาพและเกรดอ้างอิง:
   - คุณภาพมาตรฐานตามเภสัชตำรับ (USP หรือ BP) หรือเทียบเท่า
   - กรณีใช้ทางการแพทย์หรือการเพาะเลี้ยงเซลล์ ต้องระบุปราศจากเชื้อ (Sterile) และปราศจากสารก่อไข้ (Non-pyrogenic) หรือมีทะเบียนตำรับยา/ใบรับรองเครื่องมือแพทย์
   - กรณีใช้สำหรับงานชะล้างทั่วไปในห้องปฏิบัติการ อาจใช้เกรด Laboratory/Purified water ได้
3. ข้อกำหนดบรรจุภัณฑ์และการจัดเก็บ:
   - บรรจุในขวดหรือถุงพลาสติกทางการแพทย์ที่ปิดผนึกอย่างแน่นหนา ปราศจากการรั่วซึม
   - ฉลากระบุชื่อผลิตภัณฑ์ ความเข้มข้น เลขที่ผลิต (Lot/Batch No.) วันที่ผลิต และวันหมดอายุชัดเจน
   - เก็บที่อุณหภูมิห้อง หลีกเลี่ยงแสงแดดและความร้อนสูง

ให้ตอบโดยตรงตามโครงสร้างข้างต้น ไม่ต้องมีคำทักทายหรือคำลงท้าย
PROMPT;

        $promptParts = [];
        if ($nameTh !== '') {
            $promptParts[] = "ชื่อสารเคมี/รายการ (TH): {$nameTh}";
        }
        if ($nameEn !== null && $nameEn !== '') {
            $promptParts[] = "ชื่อสารเคมี/รายการ (EN): {$nameEn}";
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

    /**
     * Merges a freshly generated AI draft into whatever's already in `specification`,
     * instead of overwriting the whole field — this field can also carry real, verified
     * facts from elsewhere (e.g. `ChemicalSyncService`'s PubChem-derived formula/CAS/MW/
     * physical-description paragraph, or the original catalog import's raw name/packaging
     * notes), which must never be silently destroyed by an unverified AI draft.
     *
     * Any content before the AI block (identified by its own `SPEC_PREFIX` marker) is left
     * untouched; the AI block itself is replaced in place on a re-run rather than
     * duplicated, the same discipline `ChemicalSyncService::mergeSpecification()` already
     * applies to its own `(PubChem CID: N)` line.
     */
    public function mergeIntoSpecification(?string $existing, string $generated): string
    {
        if ($existing === null || trim($existing) === '') {
            return $generated;
        }

        $markerPos = strpos($existing, '[ร่างโดย AI');
        if ($markerPos === false) {
            return rtrim($existing)."\n\n".$generated;
        }

        $before = rtrim(substr($existing, 0, $markerPos));

        return $before === '' ? $generated : $before."\n\n".$generated;
    }
}
