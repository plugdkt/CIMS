<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ItemCategory;
use Illuminate\Database\Seeder;

/** Categories named in spec §5.2's column comment for item_categories.code. */
final class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'CHEMICAL', 'name_th' => 'สารเคมี', 'is_chemical' => true],
            ['code' => 'MATERIAL', 'name_th' => 'วัสดุ', 'is_chemical' => false],
            ['code' => 'CONSUMABLE', 'name_th' => 'วัสดุสิ้นเปลือง', 'is_chemical' => false],
            ['code' => 'GLASSWARE', 'name_th' => 'เครื่องแก้ว', 'is_chemical' => false],
        ];

        foreach ($categories as $category) {
            ItemCategory::updateOrCreate(['code' => $category['code']], $category);
        }
    }
}
