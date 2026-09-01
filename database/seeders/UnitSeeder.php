<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Seed data from spec §5.3. "box" is intentionally left out: its factor_to_base is
 * "ตามกำหนด" (varies per item/packaging), so it can't be a single fixed global row —
 * it needs a per-item conversion factor, which isn't modeled yet.
 */
final class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'mg', 'name_th' => 'มิลลิกรัม', 'dimension' => 'MASS', 'factor_to_base' => '1', 'is_base' => true, 'sort_order' => 1],
            ['code' => 'g', 'name_th' => 'กรัม', 'dimension' => 'MASS', 'factor_to_base' => '1000', 'is_base' => false, 'sort_order' => 2],
            ['code' => 'kg', 'name_th' => 'กิโลกรัม', 'dimension' => 'MASS', 'factor_to_base' => '1000000', 'is_base' => false, 'sort_order' => 3],
            ['code' => 'uL', 'name_th' => 'ไมโครลิตร', 'dimension' => 'VOLUME', 'factor_to_base' => '1', 'is_base' => true, 'sort_order' => 4],
            ['code' => 'mL', 'name_th' => 'มิลลิลิตร', 'dimension' => 'VOLUME', 'factor_to_base' => '1000', 'is_base' => false, 'sort_order' => 5],
            ['code' => 'L', 'name_th' => 'ลิตร', 'dimension' => 'VOLUME', 'factor_to_base' => '1000000', 'is_base' => false, 'sort_order' => 6],
            ['code' => 'pcs', 'name_th' => 'ชิ้น', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => true, 'sort_order' => 7],
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(['code' => $unit['code']], $unit);
        }
    }
}
