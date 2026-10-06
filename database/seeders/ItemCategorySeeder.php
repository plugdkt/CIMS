<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

/**
 * Categories named in spec §5.2's column comment for item_categories.code, plus the seven
 * further chemical types the warehouse managers actually sort stock by (user-listed
 * 2026-10-06: อาหารเลี้ยงเชื้อ / สี / น้ำตาล / ยาปฏิชีวนะ / Test Kits / Detergent / อื่นๆ).
 * 'CHEMICAL' keeps the plain "สารเคมี" type, so the chemical types are eight in all.
 */
final class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'CHEMICAL', 'name_th' => 'สารเคมี', 'is_chemical' => true],
            ['code' => 'MEDIA', 'name_th' => 'อาหารเลี้ยงเชื้อ', 'is_chemical' => true],
            ['code' => 'DYE', 'name_th' => 'สี', 'is_chemical' => true],
            ['code' => 'SUGAR', 'name_th' => 'น้ำตาล', 'is_chemical' => true],
            ['code' => 'ANTIBIOTIC', 'name_th' => 'ยาปฏิชีวนะ', 'is_chemical' => true],
            ['code' => 'TEST_KIT', 'name_th' => 'Test Kits', 'is_chemical' => true],
            ['code' => 'DETERGENT', 'name_th' => 'Detergent', 'is_chemical' => true],
            ['code' => 'OTHER_CHEM', 'name_th' => 'อื่นๆ', 'is_chemical' => true],
            ['code' => 'MATERIAL', 'name_th' => 'วัสดุ', 'is_chemical' => false],
            ['code' => 'CONSUMABLE', 'name_th' => 'วัสดุสิ้นเปลือง', 'is_chemical' => false],
            ['code' => 'GLASSWARE', 'name_th' => 'เครื่องแก้ว', 'is_chemical' => false],
        ];

        foreach ($categories as $category) {
            ItemCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
