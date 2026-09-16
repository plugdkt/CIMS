<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Chemicals\Services\ChemicalPropertyExtractor;
use App\Models\AuditLog;
use App\Models\Item;
use Illuminate\Console\Command;

final class CleanChemicalPropertiesCommand extends Command
{
    /** @var string */
    protected $signature = 'chemicals:extract-properties
                            {--dry-run : แสดงตัวอย่างการเปลี่ยนแปลงโดยไม่บันทึกลงฐานข้อมูล}
                            {--force : บันทึกทับข้อมูลเกรดหรือสถานะเดิม}
                            {--limit= : จำกัดจำนวนรายการ}';

    /** @var string */
    protected $description = 'สกัดสถานะทางกายภาพ (Powder/Liquid/Solid), เกรด (Grade) และสูตรเคมีออกจากชื่อสาร พร้อมทำความสะอาดชื่อสารเคมี';

    public function handle(ChemicalPropertyExtractor $extractor): int
    {
        $query = Item::query()->where('is_active', true)->orderBy('id');

        /** @var string|null $limitOption */
        $limitOption = $this->option('limit');
        if ($limitOption !== null && is_numeric($limitOption) && (int) $limitOption > 0) {
            $query->limit((int) $limitOption);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('ไม่พบรายการสารเคมี');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($isDryRun) {
            $this->warn('กำลังทำงานในโหมด --dry-run (ไม่บันทึกลงฐานข้อมูล)');
        }

        $this->info("เริ่มการสกัดข้อมูลและทำความสะอาดชื่อสารเคมีจำนวน {$total} รายการ...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updatedCount = 0;
        $stateExtracted = 0;
        $gradeExtracted = 0;
        $formulaExtracted = 0;
        $nameCleaned = 0;

        /** @var list<array<string, string>> $sampleChanges */
        $sampleChanges = [];

        foreach ($query->cursor() as $item) {
            $res = $extractor->extractAndClean($item->name_th);

            $hasChange = false;
            $before = [
                'name_th' => $item->name_th,
                'physical_state' => $item->physical_state,
                'grade' => $item->grade,
                'formula' => $item->formula,
            ];

            // 1. Update physical state if found and currently null (or force)
            if ($res['physical_state'] !== null && ($item->physical_state === null || $force)) {
                if ($item->physical_state !== $res['physical_state']) {
                    $item->physical_state = $res['physical_state'];
                    $stateExtracted++;
                    $hasChange = true;
                }
            }

            // 2. Update grade if found and currently null (or force)
            if ($res['grade'] !== null && ($item->grade === null || $item->grade === '' || $force)) {
                if ($item->grade !== $res['grade']) {
                    $item->grade = $res['grade'];
                    $gradeExtracted++;
                    $hasChange = true;
                }
            }

            // 3. Update formula if found from bracket and currently null (or force)
            if ($res['formula'] !== null && ($item->formula === null || $item->formula === '' || $force)) {
                if ($item->formula !== $res['formula']) {
                    $item->formula = $res['formula'];
                    $formulaExtracted++;
                    $hasChange = true;
                }
            }

            // 4. Update cleaned name_th if changed and not empty
            if ($res['clean_name'] !== '' && $res['clean_name'] !== $item->name_th) {
                // Ensure name is not trimmed to empty
                if (mb_strlen($res['clean_name']) >= 2) {
                    $item->name_th = $res['clean_name'];
                    $nameCleaned++;
                    $hasChange = true;
                }
            }

            if ($hasChange) {
                $updatedCount++;
                if (! $isDryRun) {
                    $item->save();

                    AuditLog::record(
                        action: 'PROPERTY_EXTRACT',
                        result: 'SUCCESS',
                        userId: null,
                        username: 'SYSTEM/CLI',
                        entityType: Item::class,
                        entityId: $item->id,
                        oldValue: $before,
                        newValue: [
                            'name_th' => $item->name_th,
                            'physical_state' => $item->physical_state,
                            'grade' => $item->grade,
                            'formula' => $item->formula,
                        ],
                        message: 'Extracted physical_state, grade, or cleaned name',
                    );
                }

                if (count($sampleChanges) < 10) {
                    $sampleChanges[] = [
                        'code' => $item->item_code,
                        'before' => $before['name_th'],
                        'after' => $item->name_th,
                        'state' => (string) ($item->physical_state ?? '-'),
                        'grade' => (string) ($item->grade ?? '-'),
                    ];
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['สรุปผลการทำงาน', 'จำนวน (รายการ)'],
            [
                ['สารเคมีที่มีการปรับปรุงข้อมูล', $updatedCount],
                ['สกัดสถานะทางกายภาพ (Physical State)', $stateExtracted],
                ['สกัดเกรด (Grade)', $gradeExtracted],
                ['สกัดสูตรเคมี (Formula)', $formulaExtracted],
                ['ทำความสะอาดชื่อสารเคมี (Name Cleaned)', $nameCleaned],
            ]
        );

        if (count($sampleChanges) > 0) {
            $this->newLine();
            $this->info('ตัวอย่างรายการที่ปรับปรุง:');
            $this->table(
                ['รหัส', 'ชื่อเดิม', 'ชื่อที่ทำความสะอาดแล้ว', 'สถานะ', 'เกรด'],
                $sampleChanges
            );
        }

        return self::SUCCESS;
    }
}
