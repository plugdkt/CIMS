<?php

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeChemicalsFixture(array $rows): string
{
    $path = sys_get_temp_dir().'/chemicals_import_test_'.uniqid().'.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, ['item_code', 'name', 'raw_name', 'cas_no', 'formula', 'grade', 'package_size', 'base_unit', 'packaging', 'needs_review']);
    foreach ($rows as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);

    return $path;
}

test('chemicals:import creates items from a well-formed row', function () {
    $path = writeChemicalsFixture([
        ['AS999001', 'Sodium Chloride', 'Sodium Chloride 500 g /ขวด', '7647-14-5', 'NaCl', 'AR', '500', 'g', 'ขวด', ''],
    ]);

    $this->artisan('chemicals:import', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('นำเข้าสำเร็จ: 1 รายการ');

    $item = Item::where('item_code', 'AS999001')->firstOrFail();
    expect($item->category_id)->toBe(ItemCategory::where('code', 'CHEMICAL')->value('id'));
    expect($item->name_th)->toBe('Sodium Chloride');
    expect($item->cas_no)->toBe('7647-14-5');
    expect($item->formula)->toBe('NaCl');
    expect($item->grade)->toBe('AR');
    expect($item->package_size)->toBe('500.000000');
    expect($item->base_unit_id)->toBe(Unit::where('code', 'g')->value('id'));
    expect($item->specification)->toContain('Sodium Chloride 500 g /ขวด');

    @unlink($path);
});

test('chemicals:import leaves base_unit_id/package_size null for a needs_review row', function () {
    $path = writeChemicalsFixture([
        ['AS999002', 'Unparsable Reagent', 'Unparsable Reagent /ขวด', '', '', '', '', '', 'ขวด', 'YES'],
    ]);

    $this->artisan('chemicals:import', ['path' => $path])->assertExitCode(0);

    $item = Item::where('item_code', 'AS999002')->firstOrFail();
    expect($item->base_unit_id)->toBeNull();
    expect($item->package_size)->toBeNull();

    @unlink($path);
});

test('chemicals:import skips an item_code that already exists (idempotent)', function () {
    $item = makeItem(['item_code' => 'AS999003']);

    $path = writeChemicalsFixture([
        ['AS999003', 'Duplicate Chemical', 'Duplicate Chemical 1 g /ขวด', '', '', '', '1', 'g', 'ขวด', ''],
    ]);

    $this->artisan('chemicals:import', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('ข้าม (มี item_code นี้อยู่แล้ว): 1 รายการ');

    expect(Item::where('item_code', 'AS999003')->count())->toBe(1);
    expect($item->fresh()->name_th)->not->toBe('Duplicate Chemical');

    @unlink($path);
});

test('chemicals:import skips cancelled items containing ยกเลิก', function () {
    $path = writeChemicalsFixture([
        ['AS999004', '*ยกเลิก*Methanol HDPE', '*ยกเลิก*Methanol HDPE /ขวด', '', '', '', '1', 'L', 'ขวด', ''],
        ['AS999005', '*ยกเลิกไปใช้ AS194688* Myo-Inositol', '*ยกเลิกไปใช้ AS194688* Myo-Inositol /solid', '', '', '', '100', 'g', 'ขวด', ''],
    ]);

    $this->artisan('chemicals:import', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('นำเข้าสำเร็จ: 0 รายการ');

    expect(Item::where('item_code', 'AS999004')->exists())->toBeFalse();
    expect(Item::where('item_code', 'AS999005')->exists())->toBeFalse();

    @unlink($path);
});

test('chemicals:import fails cleanly when the file does not exist', function () {
    $this->artisan('chemicals:import', ['path' => '/nonexistent/file.csv'])
        ->assertExitCode(1);
});
