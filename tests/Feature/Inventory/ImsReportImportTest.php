<?php

use App\Domain\Inventory\Services\ImsStockReportParser;
use App\Models\ImsLot;
use App\Models\ImsReceipt;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** Right edge (PDF points) of each numeric column in the university's stock-summary report. */
const IMS_REPORT_COLUMN_EDGE = [
    'open_qty' => 286.0, 'open_price' => 332.0, 'open_total' => 383.0,
    'in_qty' => 419.0, 'in_price' => 467.0, 'in_total' => 511.0,
    'out_qty' => 547.0, 'out_price' => 594.0, 'out_total' => 634.0,
    'bal_qty' => 670.0, 'bal_price' => 711.0, 'bal_total' => 762.0,
];

/**
 * Builds a one-page PDF laid out like the real report (text placed by position — the
 * parser never reads the words). ASCII only; the real file's Thai is irrelevant to it.
 *
 * @param  list<array{code: string, row_no: int, cols: array<string, string>}>  $rows
 */
function fakeStockReportPdf(array $rows, string $from = '01/10/2026', string $to = '31/10/2026'): string
{
    $run = function (float $x, float $y, string $text): string {
        return sprintf("BT /F1 7 Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $x, $y, $text);
    };

    $content = $run(17.6, 527.5, 'E0603 : Custodian');
    $content .= $run(295.1, 522.2, ": $from");
    $content .= $run(384.2, 522.2, ": $to");

    $y = 452.0;
    foreach ($rows as $row) {
        $content .= $run(50.5, $y, $row['code']);
        $content .= $run(26.1, $y + 0.3, ' '.$row['row_no']);
        foreach ($row['cols'] as $column => $value) {
            $digits = preg_match_all('/\d/', $value);
            $width = ($digits * 4.0) + ((strlen($value) - $digits) * 1.9);
            $content .= $run(IMS_REPORT_COLUMN_EDGE[$column] - 2.4 - $width, $y + 0.3, ' '.$value);
        }
        $y -= 55.0;
    }

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
}

function sampleRows(): array
{
    return [
        // 190 packs on hand at 135, 8 issued this month, 182 left.
        ['code' => 'AS100001', 'row_no' => 1, 'cols' => [
            'open_qty' => '190', 'open_price' => '135.00', 'open_total' => '25,650.00',
            'out_qty' => '8', 'out_price' => '135.00', 'out_total' => '1,080.00',
            'bal_qty' => '182', 'bal_price' => '135.00', 'bal_total' => '24,570.00',
        ]],
        // Untouched stock: only opening and closing columns.
        ['code' => 'AS100002', 'row_no' => 2, 'cols' => [
            'open_qty' => '3', 'open_price' => '642.00', 'open_total' => '1,926.00',
            'bal_qty' => '3', 'bal_price' => '642.00', 'bal_total' => '1,926.00',
        ]],
        // Fully issued: nothing left.
        ['code' => 'AS100003', 'row_no' => 3, 'cols' => [
            'open_qty' => '1', 'open_price' => '50.00', 'open_total' => '50.00',
            'out_qty' => '1', 'out_price' => '50.00', 'out_total' => '50.00',
        ]],
        // Not a catalog item (office supply).
        ['code' => 'AS999999', 'row_no' => 4, 'cols' => [
            'open_qty' => '4', 'open_price' => '5.00', 'open_total' => '20.00',
            'bal_qty' => '4', 'bal_price' => '5.00', 'bal_total' => '20.00',
        ]],
    ];
}

beforeEach(function () {
    Storage::fake('local');
    $this->lab = makeLab();
    $this->manager = labManagerUser(['lab_id' => $this->lab->id]);
    $this->gram = Unit::where('code', 'g')->firstOrFail();

    $makeItem = fn (string $code, ?string $packageSize) => Item::create([
        'item_code' => $code, 'name_th' => "สารทดสอบ $code", 'category_id' => ItemCategory::first()->id ?? 1,
        'base_unit_id' => $this->gram->id, 'package_unit_id' => $packageSize ? $this->gram->id : null,
        'package_size' => $packageSize, 'storage_class' => 'OTHER', 'reorder_point_base' => '0.000000', 'is_active' => true,
    ]);
    $this->withSize = $makeItem('AS100001', '500.000000');
    $this->withoutSize = $makeItem('AS100002', null);
    $this->fullyIssued = $makeItem('AS100003', '100.000000');

    $this->upload = fn (?string $bytes = null) => UploadedFile::fake()->createWithContent('IMS.pdf', $bytes ?? fakeStockReportPdf(sampleRows()));
});

test('the parser puts every number in its own column by position, even when neighbouring cells are empty', function () {
    $path = tempnam(sys_get_temp_dir(), 'ims').'.pdf';
    file_put_contents($path, fakeStockReportPdf(sampleRows()));

    $parsed = (new ImsStockReportParser())->parse($path);
    unlink($path);

    expect($parsed['custodian'])->toBe('E0603')
        ->and($parsed['period_from'])->toBe('01/10/2026')
        ->and($parsed['period_to'])->toBe('31/10/2026')
        ->and($parsed['rows'])->toHaveCount(4);

    [$a, $b, $c] = $parsed['rows'];
    expect($a)->toMatchArray([
        'code' => 'AS100001', 'row_no' => 1, 'open_qty' => '190', 'open_total' => '25650.00',
        'out_qty' => '8', 'out_total' => '1080.00', 'bal_qty' => '182', 'bal_price' => '135.00', 'bal_total' => '24570.00',
        'balanced' => true,
    ])->and($a)->not->toHaveKey('in_qty');

    // No issue column present for the untouched row, and no closing balance for the emptied one.
    expect($b)->not->toHaveKey('out_qty')->and($b['bal_qty'])->toBe('3')
        ->and($c['out_qty'])->toBe('1')->and($c)->not->toHaveKey('bal_qty');
});

test('a row whose opening + received - issued does not equal the balance is flagged', function () {
    $rows = [['code' => 'AS100001', 'row_no' => 1, 'cols' => [
        'open_qty' => '10', 'open_price' => '5.00', 'open_total' => '50.00',
        'out_qty' => '2', 'out_price' => '5.00', 'out_total' => '10.00',
        'bal_qty' => '9', 'bal_price' => '5.00', 'bal_total' => '45.00',
    ]]];
    $path = tempnam(sys_get_temp_dir(), 'ims').'.pdf';
    file_put_contents($path, fakeStockReportPdf($rows));

    $parsed = (new ImsStockReportParser())->parse($path);
    unlink($path);

    expect($parsed['rows'][0]['balanced'])->toBeFalse();
});

test('uploading the report fills a draft with the closing balance of catalog items only', function () {
    $response = $this->actingAs($this->manager)->post(route('ims.receipts.store'), [
        'doc_no' => 'IMS-OCT', 'pdf' => ($this->upload)(), 'import_report' => 1,
    ]);

    $receipt = ImsReceipt::firstOrFail();
    $response->assertRedirect(route('ims.receipts.show', $receipt));

    $lines = $receipt->lines()->get();
    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('item_id')->all())->toBe([$this->withSize->id, $this->withoutSize->id]);

    expect((float) $lines[0]->pack_qty)->toEqual(182.0)->and((float) $lines[0]->unit_price)->toEqual(135.0)
        ->and((float) $lines[1]->pack_qty)->toEqual(3.0)->and((float) $lines[1]->unit_price)->toEqual(642.0);

    $summary = $receipt->import_summary;
    expect($summary['imported'])->toBe(2)
        ->and($summary['no_stock'])->toBe(1)
        ->and($summary['unmatched'])->toHaveCount(1)
        ->and($summary['unmatched'][0]['code'])->toBe('AS999999')
        ->and($summary['custodian'])->toBe('E0603');

    // The review page lists what was skipped.
    $this->actingAs($this->manager)->get(route('ims.receipts.show', $receipt))
        ->assertOk()->assertSee('AS999999');
});

test('the same file cannot be imported twice while the first document is not cancelled', function () {
    $bytes = fakeStockReportPdf(sampleRows());

    $this->actingAs($this->manager)->post(route('ims.receipts.store'), ['pdf' => ($this->upload)($bytes), 'import_report' => 1])->assertRedirect();
    $this->actingAs($this->manager)->post(route('ims.receipts.store'), ['pdf' => ($this->upload)($bytes), 'import_report' => 1])
        ->assertSessionHasErrors('pdf');

    expect(ImsReceipt::count())->toBe(1);

    ImsReceipt::firstOrFail()->update(['status' => 'CANCELLED']);
    $this->actingAs($this->manager)->post(route('ims.receipts.store'), ['pdf' => ($this->upload)($bytes), 'import_report' => 1])->assertRedirect();
    expect(ImsReceipt::count())->toBe(2);
});

test('a PDF that is not this report leaves nothing behind', function () {
    $this->actingAs($this->manager)->post(route('ims.receipts.store'), [
        'pdf' => ($this->upload)(fakeStockReportPdf([])), 'import_report' => 1,
    ])->assertSessionHasErrors('pdf');

    expect(ImsReceipt::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('ims-receipts'))->toBe([]);
});

test('confirming derives the quantity from packs x catalog package size and prices each pack', function () {
    $this->actingAs($this->manager)->post(route('ims.receipts.store'), ['pdf' => ($this->upload)(), 'import_report' => 1]);
    $receipt = ImsReceipt::firstOrFail();

    // The second line has no package size in the catalog, so it blocks confirmation...
    $this->actingAs($this->manager)->post(route('ims.receipts.confirm', $receipt))->assertSessionHasErrors('confirm');
    expect(ImsLot::count())->toBe(0);

    // ...until the manager gives its total quantity.
    $line = $receipt->lines()->where('item_id', $this->withoutSize->id)->firstOrFail();
    $this->actingAs($this->manager)->put(route('ims.receipts.lines.update', [$receipt, $line]), [
        'pack_qty' => '3', 'unit_price' => '642', 'qty' => '750', 'unit_id' => $this->gram->id, 'lot_no' => 'L-77',
    ])->assertRedirect();

    $this->actingAs($this->manager)->post(route('ims.receipts.confirm', $receipt))->assertRedirect();

    $lot = ImsLot::where('item_id', $this->withSize->id)->firstOrFail();
    expect((float) $lot->qty_received_base)->toEqual(91000.0)   // 182 packs x 500 g
        ->and((float) $lot->pack_size_base)->toEqual(500.0)
        ->and((float) $lot->unit_price)->toEqual(135.0);

    $manual = ImsLot::where('item_id', $this->withoutSize->id)->firstOrFail();
    expect((float) $manual->qty_received_base)->toEqual(750.0)
        ->and((float) $manual->pack_size_base)->toEqual(250.0)  // 750 g / 3 packs
        ->and($manual->lot_no)->toBe('L-77');
});

test('a bottle cut from a lot is priced by the share of a pack it holds', function () {
    $this->actingAs($this->manager)->post(route('ims.receipts.store'), ['pdf' => ($this->upload)(), 'import_report' => 1]);
    $receipt = ImsReceipt::firstOrFail();
    $line = $receipt->lines()->where('item_id', $this->withoutSize->id)->firstOrFail();
    $line->update(['qty' => '750', 'unit_id' => $this->gram->id]);
    $this->actingAs($this->manager)->post(route('ims.receipts.confirm', $receipt));

    $lot = ImsLot::where('item_id', $this->withSize->id)->firstOrFail();
    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), [
        'ims_doc_no' => 'WD-1', 'tracking_type' => 'CONTAINER', 'container_count' => 2, 'qty_per_container' => '250',
        'unit_id' => $this->gram->id, 'location_id' => makeLocationForLab($this->lab)->id,
    ])->assertRedirect();

    // 135 baht per 500 g pack -> a 250 g bottle is half a pack.
    foreach (\App\Models\Container::where('ims_lot_id', $lot->id)->get() as $container) {
        expect((float) $container->unit_price)->toEqual(67.5);
    }
});

test('the parser accepts 7-digit codes and lower-case "As" prefixes, normalised to upper case', function () {
    $rows = [
        ['code' => 'AS1940110', 'row_no' => 1, 'cols' => ['open_qty' => '2', 'open_price' => '10.00', 'open_total' => '20.00', 'bal_qty' => '2', 'bal_price' => '10.00', 'bal_total' => '20.00']],
        ['code' => 'As070770', 'row_no' => 2, 'cols' => ['open_qty' => '1', 'open_price' => '5.00', 'open_total' => '5.00', 'bal_qty' => '1', 'bal_price' => '5.00', 'bal_total' => '5.00']],
    ];
    $path = tempnam(sys_get_temp_dir(), 'ims').'.pdf';
    file_put_contents($path, fakeStockReportPdf($rows));

    $parsed = (new ImsStockReportParser())->parse($path);
    unlink($path);

    expect(array_column($parsed['rows'], 'code'))->toBe(['AS1940110', 'AS070770']);
});
