<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Chemicals\Services\ChemicalSpecificationAiService;
use App\Models\AuditLog;
use App\Models\Item;
use Illuminate\Console\Command;

final class GenerateAiSpecificationsCommand extends Command
{
    /** @var string */
    protected $signature = 'chemicals:ai-generate-specs
                            {--limit= : จำกัดจำนวนรายการ}
                            {--delay=1000 : หน่วงเวลาระหว่างการเรียก API (มิลลิวินาที)}
                            {--force : สังเคราะห์ทับรายการที่มี specification อยู่แล้ว}
                            {--dry-run : ทดสอบการทำงานโดยไม่บันทึกลงฐานข้อมูล}';

    /** @var string */
    protected $description = 'สร้างคุณลักษณะเฉพาะ (Specification) ของสารเคมีด้วย AI เป็นชุด';

    public function handle(ChemicalSpecificationAiService $aiService): int
    {
        $query = Item::query()->where('is_active', true);

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('specification')->orWhere('specification', '');
            });
        }

        $query->orderBy('id');

        /** @var string|null $limitOption */
        $limitOption = $this->option('limit');
        $limit = ($limitOption !== null && is_numeric($limitOption) && (int) $limitOption > 0)
            ? (int) $limitOption
            : null;

        $unlimitedTotal = (clone $query)->count();
        $total = $limit !== null ? min($limit, $unlimitedTotal) : $unlimitedTotal;

        if ($total === 0) {
            $this->info('ไม่พบรายการสารเคมีที่ต้องสร้างสเปก');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('กำลังทำงานในโหมด --dry-run (ไม่บันทึกลงฐานข้อมูล)');
        }

        $this->info("เริ่มสร้างคุณลักษณะเฉพาะด้วย AI สำหรับสารเคมีจำนวน {$total} รายการ...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $processed = 0;

        /** @var string|int $delayOption */
        $delayOption = $this->option('delay');
        $delayMs = max(0, (int) $delayOption);

        foreach ($query->cursor() as $item) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }
            $processed++;

            $th = trim($item->name_th);
            $en = $item->name_en !== null ? trim($item->name_en) : null;
            $cas = $item->cas_no !== null ? trim($item->cas_no) : null;
            $formula = $item->formula !== null ? trim($item->formula) : null;

            if ($th === '' && ($en === null || $en === '') && ($cas === null || $cas === '')) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $spec = $aiService->generateSpecification(
                nameTh: $th,
                nameEn: $en,
                casNo: $cas,
                formula: $formula,
            );

            if ($spec !== null && $spec !== '') {
                if (! $isDryRun) {
                    $before = ['specification' => $item->specification];
                    $item->specification = $spec;
                    $item->save();

                    AuditLog::record(
                        action: 'AI_SPEC_GENERATE',
                        result: 'SUCCESS',
                        userId: null,
                        username: 'SYSTEM/CLI',
                        entityType: Item::class,
                        entityId: $item->id,
                        oldValue: $before,
                        newValue: ['specification' => $spec],
                        message: 'Generated via KKU GenAI Gateway',
                    );
                }
                $success++;
            } else {
                $failed++;
            }

            $bar->advance();

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['ผลการทำงาน', 'จำนวน (รายการ)'],
            [
                ['สร้างสเปกสำเร็จ', $success],
                ['ข้าม (ไม่มีชื่อ/CAS ให้ประมวลผล)', $skipped],
                ['ไม่สำเร็จ (API ขัดข้อง หรือคืนค่าว่าง)', $failed],
                ['รวมทั้งหมดที่ประมวลผล', $processed],
            ]
        );

        return self::SUCCESS;
    }
}
