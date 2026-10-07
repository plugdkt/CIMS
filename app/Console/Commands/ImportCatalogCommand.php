<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Chemicals\Services\CatalogNameParser;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan catalog:import <file> --category=CODE [--dry-run]` — loads a raw two-column
 * export of the central store ("รหัสสินค้า","ชื่อสินค้า") into the item catalog, every row,
 * with no chemical/non-chemical triage (unlike `chemicals:import`, which reads an already
 * curated file). The point is completeness: an IMS purchase document can only be matched
 * line by line if every code the university store uses exists here.
 *
 * A non-chemical category (is_chemical = false) is read in "count mode": one piece per pack
 * unless the name states a counted size.
 *
 * Idempotent by `item_code` (existing codes are skipped, never overwritten). Codes are
 * upper-cased ("As070770" -> "AS070770"); rows marked "ยกเลิก" are imported inactive.
 */
final class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import {path : CSV with รหัสสินค้า,ชื่อสินค้า} {--category=CHEMICAL : item_categories.code} {--dry-run : count only, write nothing}';

    protected $description = 'นำเข้าทะเบียนรายการจากไฟล์ CSV ของคลังกลางทุกบรรทัด (รหัส AS, ชื่อสินค้า) โดยไม่คัดกรอง';

    public function handle(CatalogNameParser $parser): int
    {
        /** @var string $path */
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์: {$path}");

            return self::FAILURE;
        }

        $categoryCode = (string) $this->option('category');
        $category = ItemCategory::where('code', $categoryCode)->first();
        if ($category === null) {
            $this->error("ไม่พบหมวดหมู่ {$categoryCode} ใน item_categories — รัน ItemCategorySeeder ก่อน");

            return self::FAILURE;
        }

        $categoryId = $category->id;
        // A non-chemical material is counted, not measured: "Beaker 1000 ml /ชิ้น" is one
        // piece (the 1000 ml is its capacity, not what a pack contains), so only a counted
        // size such as "100 ชิ้น /กล่อง" is kept and everything else is one piece per pack.
        $countMode = ! $category->is_chemical;

        /** @var array<string, int> $unitIds */
        $unitIds = Unit::pluck('id', 'code')->all();
        $dryRun = (bool) $this->option('dry-run');

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("เปิดไฟล์ไม่ได้: {$path}");

            return self::FAILURE;
        }

        $existing = array_flip(Item::pluck('item_code')->all());
        $seen = [];
        $created = 0;
        $existingSkipped = 0;
        $withSize = 0;
        $inactive = 0;
        $failed = [];

        $header = fgetcsv($fh);
        if ($header !== false && isset($header[0])) {
            $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");
        }

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) {
                continue;
            }

            $code = strtoupper(trim((string) $row[0]));
            $raw = trim((string) $row[1]);
            if ($code === '' || $raw === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            if (isset($existing[$code])) {
                $existingSkipped++;

                continue;
            }

            $parsed = $parser->parse($raw);
            if ($countMode && $parsed['unit'] !== 'pcs') {
                $parsed['package_size'] = '1';
                $parsed['unit'] = 'pcs';
            }
            $cancelled = str_contains($raw, 'ยกเลิก');
            $baseUnitId = $parsed['unit'] !== null ? ($unitIds[$parsed['unit']] ?? null) : null;
            $withSize += $parsed['package_size'] !== null && $baseUnitId !== null ? 1 : 0;
            $inactive += $cancelled ? 1 : 0;

            if ($dryRun) {
                $created++;

                continue;
            }

            try {
                Item::create([
                    'item_code' => $code,
                    'category_id' => $categoryId,
                    'name_th' => mb_substr($parsed['name'], 0, 255),
                    'package_size' => $baseUnitId !== null ? $parsed['package_size'] : null,
                    'base_unit_id' => $baseUnitId,
                    'specification' => "นำเข้าจากระบบคลังกลาง (รหัส AS)\nชื่อเดิม: {$raw}"
                        .($parsed['packaging'] !== null ? "\nหน่วยบรรจุ: {$parsed['packaging']}" : ''),
                    'is_active' => ! $cancelled,
                ]);
                $created++;
            } catch (Throwable $e) {
                $failed[] = "{$code}: {$e->getMessage()}";
            }
        }

        fclose($fh);

        $verb = $dryRun ? 'จะนำเข้า' : 'นำเข้าสำเร็จ';
        $this->info("{$verb}: {$created} รายการ (หมวด {$categoryCode})");
        $this->info("  แยกขนาดบรรจุ+หน่วยได้: {$withSize} รายการ, ไม่ได้: ".($created - $withSize).' รายการ (ผู้ดูแลคลังกรอกเอง)');
        $this->info("  ยกเลิกแล้ว (นำเข้าเป็นไม่ใช้งาน): {$inactive} รายการ");
        $this->info("ข้าม (มี item_code นี้อยู่แล้ว): {$existingSkipped} รายการ");

        if ($failed !== []) {
            $this->error('ล้มเหลว: '.count($failed).' รายการ');
            foreach (array_slice($failed, 0, 20) as $line) {
                $this->line("  - {$line}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
