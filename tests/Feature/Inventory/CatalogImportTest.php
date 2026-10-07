<?php

use App\Domain\Chemicals\Services\CatalogNameParser;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('catalog names', [
    'size and unit before the packaging slash' => ['Asiatic Acid 500 mg /ขวด', 'Asiatic Acid', '500', 'mg', 'ขวด'],
    'thai unit word' => ['โซเดียมไฮดรอกไซด์ 0.1 N 1 ลิตร /ขวด', 'โซเดียมไฮดรอกไซด์ 0.1 N', '1', 'L', 'ขวด'],
    'a slash inside the name does not confuse it' => ['Insulin Mixtard hm 70/30 10 ml /ขวด', 'Insulin Mixtard hm 70/30', '10', 'mL', 'ขวด'],
    'thousands separator' => ['Ethanol AR 2,500 mL /ขวด', 'Ethanol AR', '2500', 'mL', 'ขวด'],
    'counted pack' => ['กระดาษถ่ายเอกสาร A4 80g 500 แผ่น /รีม', 'กระดาษถ่ายเอกสาร A4 80g', '500', 'pcs', 'รีม'],
]);

test('the name parser separates name, pack size, unit and packaging', function (string $raw, string $name, string $size, string $unit, string $pack) {
    expect((new CatalogNameParser())->parse($raw))->toBe([
        'name' => $name, 'package_size' => $size, 'unit' => $unit, 'packaging' => $pack,
    ]);
})->with('catalog names');

test('a name it cannot read is kept whole, with no guessed size', function () {
    $parsed = (new CatalogNameParser())->parse('Helium gas UHP grade 7 คิว /ถัง');

    expect($parsed['package_size'])->toBeNull()
        ->and($parsed['unit'])->toBeNull()
        ->and($parsed['name'])->toBe('Helium gas UHP grade 7 คิว /ถัง');

    expect((new CatalogNameParser())->parse('Tube 10 x 5 ml /กล่อง')['package_size'])->toBeNull();
});

function writeCatalogCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'cat').'.csv';
    $fh = fopen($path, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['รหัสสินค้า', 'ชื่อสินค้า']);
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);

    return $path;
}

test('catalog:import loads every row of a raw export, normalising codes and skipping existing ones', function () {
    $path = writeCatalogCsv([
        ['AS198197', 'Asiatic Acid 500 mg /ขวด'],
        ['As070770', 'กระดาษทดสอบ /แผ่น'],
        ['AS1940110', 'สารที่รหัสเจ็ดหลัก 100 g /ขวด'],
        ['AS000001', 'ยกเลิก สารเก่า 1 g /ขวด'],
        ['AS198197', 'แถวซ้ำในไฟล์เดียวกัน 1 g /ขวด'],
    ]);
    Item::create(['item_code' => 'AS000002', 'name_th' => 'มีอยู่แล้ว', 'category_id' => ItemCategory::first()->id, 'storage_class' => 'OTHER', 'reorder_point_base' => '0.000000', 'is_active' => true]);

    $this->artisan('catalog:import', ['path' => $path, '--category' => 'CHEMICAL'])->assertSuccessful();

    $asiatic = Item::where('item_code', 'AS198197')->firstOrFail();
    expect($asiatic->name_th)->toBe('Asiatic Acid')
        ->and((float) $asiatic->package_size)->toEqual(500.0)
        ->and($asiatic->baseUnit?->code)->toBe('mg')
        ->and($asiatic->packageUnit?->code)->toBe('mg')
        ->and($asiatic->category?->code)->toBe('CHEMICAL')
        ->and($asiatic->is_active)->toBeTrue()
        ->and($asiatic->specification)->toContain('Asiatic Acid 500 mg /ขวด');

    expect(Item::where('item_code', 'AS070770')->exists())->toBeTrue()          // "As" upper-cased
        ->and(Item::where('item_code', 'AS1940110')->exists())->toBeTrue()      // 7-digit code
        ->and(Item::where('item_code', 'AS000001')->firstOrFail()->is_active)->toBeFalse()
        ->and(Item::where('item_code', 'AS198197')->count())->toBe(1);

    // Re-running changes nothing.
    $before = Item::count();
    $this->artisan('catalog:import', ['path' => $path, '--category' => 'CHEMICAL'])->assertSuccessful();
    expect(Item::count())->toBe($before);
});

test('--dry-run writes nothing', function () {
    $path = writeCatalogCsv([['AS198197', 'Asiatic Acid 500 mg /ขวด']]);

    $this->artisan('catalog:import', ['path' => $path, '--dry-run' => true])->assertSuccessful();

    expect(Item::where('item_code', 'AS198197')->exists())->toBeFalse();
});

test('a non-chemical category is counted: one piece per pack unless the name gives a counted size', function () {
    $path = writeCatalogCsv([
        ['AS075000', 'Beaker 1000 ml /ชิ้น'],
        ['AS075001', 'ไส้กรอง 100 ชิ้น /กล่อง'],
        ['AS075002', 'ปากกา'],
    ]);

    $this->artisan('catalog:import', ['path' => $path, '--category' => 'MATERIAL'])->assertSuccessful();

    $beaker = Item::where('item_code', 'AS075000')->firstOrFail();
    expect($beaker->baseUnit?->code)->toBe('pcs')->and((float) $beaker->package_size)->toEqual(1.0)
        ->and($beaker->category?->code)->toBe('MATERIAL');

    expect((float) Item::where('item_code', 'AS075001')->firstOrFail()->package_size)->toEqual(100.0)
        ->and((float) Item::where('item_code', 'AS075002')->firstOrFail()->package_size)->toEqual(1.0);
});

test('an unknown category is refused', function () {
    $this->artisan('catalog:import', ['path' => writeCatalogCsv([]), '--category' => 'NOPE'])->assertFailed();
});
