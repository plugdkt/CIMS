<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan chemicals:import [path]` — one-off bulk import of the central-store
 * chemical catalog (item_code kept verbatim from the "AS" codes so it stays
 * reconcilable against that system), curated + parsed out of a raw CSV export
 * (see CHANGELOG.md's entry for how the "real chemical" filter and quantity/unit
 * parsing were derived — this command only does the DB write side).
 *
 * Idempotent: an item_code that already exists is skipped, not overwritten, so this
 * is safe to re-run (e.g. after fixing a handful of `needs_review` rows by hand and
 * re-running against the same file).
 */
final class ImportChemicalsCommand extends Command
{
    protected $signature = 'chemicals:import {path=database/data/chemicals_import.csv}';

    protected $description = 'นำเข้าทะเบียนสารเคมีจากไฟล์ CSV (รหัส AS ของคลังกลาง) เข้าตาราง items';

    public function handle(): int
    {
        /** @var string $path */
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์: {$path}");

            return self::FAILURE;
        }

        $categoryId = ItemCategory::where('code', 'CHEMICAL')->value('id');
        if ($categoryId === null) {
            $this->error('ไม่พบหมวดหมู่ CHEMICAL ใน item_categories — รัน seeder ให้ครบก่อน');

            return self::FAILURE;
        }

        /** @var array<string, int> $unitIds */
        $unitIds = Unit::pluck('id', 'code')->all();

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("เปิดไฟล์ไม่ได้: {$path}");

            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        $created = 0;
        $skippedDuplicate = 0;
        $failed = [];

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) {
                continue;
            }

            [$itemCode, $name, $rawName, $casNo, $formula, $grade, $packageSize, $baseUnitCode, $packaging] = array_pad($row, 9, null);
            $itemCode = trim((string) $itemCode);

            if ($itemCode === '') {
                continue;
            }

            if (str_contains((string) $name, 'ยกเลิก') || str_contains((string) $rawName, 'ยกเลิก')) {
                continue;
            }

            if (Item::where('item_code', $itemCode)->exists()) {
                $skippedDuplicate++;

                continue;
            }

            try {
                DB::transaction(function () use (
                    $categoryId,
                    $unitIds,
                    $itemCode,
                    $name,
                    $rawName,
                    $casNo,
                    $formula,
                    $grade,
                    $packageSize,
                    $baseUnitCode,
                    $packaging,
                ) {
                    $baseUnitId = ($baseUnitCode !== null && $baseUnitCode !== '') ? ($unitIds[$baseUnitCode] ?? null) : null;

                    Item::create([
                        'item_code' => $itemCode,
                        'category_id' => $categoryId,
                        'name_th' => mb_substr((string) ($name !== '' ? $name : $rawName), 0, 255),
                        'cas_no' => $casNo !== '' ? mb_substr((string) $casNo, 0, 20) : null,
                        'formula' => $formula !== '' ? mb_substr((string) $formula, 0, 128) : null,
                        'grade' => $grade !== '' ? mb_substr((string) $grade, 0, 64) : null,
                        'package_size' => $packageSize !== '' ? $packageSize : null,
                        'base_unit_id' => $baseUnitId,
                        'specification' => "นำเข้าจากระบบคลังกลาง (รหัส AS)\nชื่อเดิม: {$rawName}"
                            .($packaging !== '' ? "\nหน่วยบรรจุ: {$packaging}" : ''),
                        'is_active' => true,
                    ]);
                });
                $created++;
            } catch (\Throwable $e) {
                $failed[] = "{$itemCode}: {$e->getMessage()}";
            }
        }

        fclose($fh);

        $this->info("นำเข้าสำเร็จ: {$created} รายการ");
        $this->info("ข้าม (มี item_code นี้อยู่แล้ว): {$skippedDuplicate} รายการ");

        if ($failed !== []) {
            $this->error('ล้มเหลว: '.count($failed).' รายการ');
            foreach (array_slice($failed, 0, 20) as $line) {
                $this->line("  - {$line}");
            }
            if (count($failed) > 20) {
                $this->line('  ... (ดูรายการที่เหลือโดยตรวจสอบ log)');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
