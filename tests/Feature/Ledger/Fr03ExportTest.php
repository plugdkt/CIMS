<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Reporting\Exports\Fr03Export;
use App\Domain\Reporting\Services\Fr03PdfService;
use App\Models\Container;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

function seedLedgerForExport(): array
{
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = User::factory()->create();
    $container = Container::create([
        'item_id' => $item->id,
        'barcode' => 'BC-EXPORT-1',
        'received_at' => now()->toDateString(),
        'initial_qty_base' => '0.000000',
        'remaining_qty_base' => '0.000000',
        'status' => 'SEALED',
    ]);
    $ledgerService = app(LedgerService::class);
    $ctx = new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id, receiverName: 'สมชาย ใจดี');
    $ledgerService->receive($container->id, '100.000000', $ctx);
    $ledgerService->issue($container->id, '40.000000', $ctx);

    return [$item, $g];
}

test('Fr03Export produces the same rows and headings as the on-screen ledger', function () {
    [$item, $g] = seedLedgerForExport();

    $export = new Fr03Export($item, new LedgerFilter(), $g);
    $rows = $export->collection();

    expect($export->headings())->toBe([
        'วัน/เดือน/ปี', 'ประเภท', 'ผู้จ่าย', 'ผู้รับ', 'รับ', 'จ่าย', 'คงเหลือ', 'ลงชื่อผู้รับ', 'หมายเหตุ',
    ]);
    expect($rows)->toHaveCount(2);
    expect($rows[0][4])->toBe('100.000000 g');
    expect($rows[1][5])->toBe('40.000000 g');
    expect($rows[1][6])->toBe('60.000000 g');
});

test('Fr03Export respects the txn_type filter', function () {
    [$item, $g] = seedLedgerForExport();

    $export = new Fr03Export($item, new LedgerFilter(txnType: 'ISSUE'), $g);

    expect($export->collection())->toHaveCount(1);
});

test('Fr03Export converts to the requested display unit', function () {
    [$item] = seedLedgerForExport();
    $kg = Unit::where('code', 'kg')->firstOrFail();

    $export = new Fr03Export($item, new LedgerFilter(), $kg);
    $rows = $export->collection();

    expect($rows[0][4])->toBe('0.100000 kg');
});

test('the Excel writer produces a readable xlsx with the correct data (end-to-end)', function () {
    [$item, $g] = seedLedgerForExport();

    $export = new Fr03Export($item, new LedgerFilter(), $g);
    $raw = Excel::raw($export, ExcelWriter::XLSX);

    $tmpFile = tempnam(sys_get_temp_dir(), 'fr03').'.xlsx';
    file_put_contents($tmpFile, $raw);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('วัน/เดือน/ปี');
    expect($sheet->getCell('E2')->getValue())->toBe('100.000000 g');

    unlink($tmpFile);
});

test('Fr03PdfService renders a non-empty PDF', function () {
    [$item, $g] = seedLedgerForExport();

    $pdf = app(Fr03PdfService::class)->render($item, new LedgerFilter(), $g);

    expect($pdf)->toStartWith('%PDF');
});

test('a user without ledger.view gets 403 on both export routes', function () {
    $user = User::factory()->create();
    $item = makeItem();

    $this->actingAs($user)->get(route('items.ledger.export.pdf', $item))->assertStatus(403);
    $this->actingAs($user)->get(route('items.ledger.export.excel', $item))->assertStatus(403);
});

test('SCIENTIST can download the PDF and Excel exports', function () {
    [$item] = seedLedgerForExport();
    $scientist = scientistUser();

    $pdfResponse = $this->actingAs($scientist)->get(route('items.ledger.export.pdf', $item));
    $pdfResponse->assertOk();
    $pdfResponse->assertHeader('Content-Type', 'application/pdf');

    $excelResponse = $this->actingAs($scientist)->get(route('items.ledger.export.excel', $item));
    $excelResponse->assertOk();
});
