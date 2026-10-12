<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * Seed data from spec §5.3, plus packaging units (2026-10-12). Spec left "box" out because a box
 * holds a different amount per item, so it has no single fixed factor. The central store counts
 * many catalog items by the pack they come in (กล่อง/ชุด/แพ็ค/…) with no size the catalog can
 * derive, and an IMS receipt line has to be able to say "3 กล่อง". Each such unit is COUNT with
 * factor 1 — the item is then counted in that pack, exactly like catalog:import's count mode
 * ("one piece per pack"). What a pack contains is the item's own package_size, not the unit.
 * Consequence: these units and `pcs` convert 1:1, so one item should be received in one of them
 * consistently; the base unit freezes on the item's first receipt.
 */
final class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'ug', 'name_th' => 'ไมโครกรัม', 'dimension' => 'MASS', 'factor_to_base' => '0.001', 'is_base' => false, 'sort_order' => 0],
            ['code' => 'mg', 'name_th' => 'มิลลิกรัม', 'dimension' => 'MASS', 'factor_to_base' => '1', 'is_base' => true, 'sort_order' => 1],
            ['code' => 'g', 'name_th' => 'กรัม', 'dimension' => 'MASS', 'factor_to_base' => '1000', 'is_base' => false, 'sort_order' => 2],
            ['code' => 'kg', 'name_th' => 'กิโลกรัม', 'dimension' => 'MASS', 'factor_to_base' => '1000000', 'is_base' => false, 'sort_order' => 3],
            ['code' => 'uL', 'name_th' => 'ไมโครลิตร', 'dimension' => 'VOLUME', 'factor_to_base' => '1', 'is_base' => true, 'sort_order' => 4],
            ['code' => 'mL', 'name_th' => 'มิลลิลิตร', 'dimension' => 'VOLUME', 'factor_to_base' => '1000', 'is_base' => false, 'sort_order' => 5],
            ['code' => 'L', 'name_th' => 'ลิตร', 'dimension' => 'VOLUME', 'factor_to_base' => '1000000', 'is_base' => false, 'sort_order' => 6],
            ['code' => 'pcs', 'name_th' => 'ชิ้น', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => true, 'sort_order' => 7],
            ['code' => 'box', 'name_th' => 'กล่อง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 8],
            ['code' => 'set', 'name_th' => 'ชุด', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 9],
            ['code' => 'pack', 'name_th' => 'แพ็ค', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 10],
            ['code' => 'bottle', 'name_th' => 'ขวด', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 11],
            ['code' => 'bag', 'name_th' => 'ถุง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 12],
            ['code' => 'tube', 'name_th' => 'หลอด', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 13],
            ['code' => 'bundle', 'name_th' => 'ห่อ', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 14],
            ['code' => 'gallon', 'name_th' => 'แกลลอน', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 15],
            ['code' => 'drum', 'name_th' => 'ถัง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 16],
            ['code' => 'can', 'name_th' => 'กระป๋อง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 17],
            ['code' => 'jar', 'name_th' => 'กระปุก', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 18],
            ['code' => 'sachet', 'name_th' => 'ซอง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 19],
            ['code' => 'strip', 'name_th' => 'แผง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 20],
            ['code' => 'sheet', 'name_th' => 'แผ่น', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 21],
            ['code' => 'roll', 'name_th' => 'ม้วน', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 22],
            ['code' => 'pair', 'name_th' => 'คู่', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 23],
            ['code' => 'dozen', 'name_th' => 'โหล', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 24],
            ['code' => 'carton', 'name_th' => 'ลัง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 25],
            ['code' => 'cartridge', 'name_th' => 'ตลับ', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 26],
            ['code' => 'vial', 'name_th' => 'ไวแอล', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 27],
            ['code' => 'ampoule', 'name_th' => 'แอมพูล', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 28],
            ['code' => 'kit', 'name_th' => 'ชุดตรวจ', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 29],
            ['code' => 'unit', 'name_th' => 'อัน', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 30],
            ['code' => 'machine', 'name_th' => 'เครื่อง', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 31],
            ['code' => 'sack', 'name_th' => 'กระสอบ', 'dimension' => 'COUNT', 'factor_to_base' => '1', 'is_base' => false, 'sort_order' => 32],
        ];

        foreach ($units as $unit) {
            Unit::updateOrCreate(['code' => $unit['code']], $unit);
        }
    }
}
