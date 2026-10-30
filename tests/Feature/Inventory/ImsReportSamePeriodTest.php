<?php

use App\Models\ImsReceipt;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Minimal one-row report PDF (text placed by position, like the real one). $balQty makes two
 * files of the same period differ byte for byte, as two exports of the same report would.
 */
function samePeriodPdf(string $from, string $to, string $balQty = '5', string $custodian = 'E0603'): string
{
    $run = fn (float $x, float $y, string $t): string => sprintf("BT /F1 7 Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $x, $y, $t);

    $content = $run(17.6, 527.5, "$custodian : Custodian");
    $content .= $run(295.1, 522.2, ": $from");
    $content .= $run(384.2, 522.2, ": $to");
    $content .= $run(50.5, 452.0, 'AS100001');
    $content .= $run(26.1, 452.3, ' 1');
    $edge = ['open_qty' => 286.0, 'bal_qty' => 670.0];
    foreach (['open_qty' => $balQty, 'bal_qty' => $balQty] as $col => $v) {
        $content .= $run($edge[$col] - 2.4 - (strlen($v) * 4.0), 452.3, ' '.$v);
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

beforeEach(function () {
    Storage::fake('local');
    $this->lab = makeLab();
    $this->manager = labManagerUser(['lab_id' => $this->lab->id]);
    $gram = Unit::where('code', 'g')->firstOrFail();
    Item::create([
        'item_code' => 'AS100001', 'name_th' => 'สารทดสอบ', 'category_id' => ItemCategory::first()->id ?? 1,
        'base_unit_id' => $gram->id, 'package_unit_id' => $gram->id, 'package_size' => '500.000000',
        'storage_class' => 'OTHER', 'reorder_point_base' => '0.000000', 'is_active' => true,
    ]);

    $this->importAs = fn ($user, string $bytes) => $this->actingAs($user)->post(route('ims.receipts.store'), [
        'pdf' => UploadedFile::fake()->createWithContent('IMS.pdf', $bytes), 'import_report' => 1,
    ]);
});

test('the same report period re-exported with different bytes is refused and leaves nothing behind', function () {
    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '5'))->assertRedirect();
    $files = Storage::disk('local')->allFiles('ims-receipts');

    $response = ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '7'));

    $response->assertSessionHasErrors('pdf');
    expect(session('errors')->first('pdf'))->toContain('01/10/2026')->toContain('ยกเลิกเอกสารเดิม')
        ->and(ImsReceipt::count())->toBe(1)
        ->and(Storage::disk('local')->allFiles('ims-receipts'))->toBe($files);
});

test('a different report period is accepted', function () {
    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026'))->assertRedirect();
    ($this->importAs)($this->manager, samePeriodPdf('01/11/2026', '30/11/2026'))->assertRedirect();

    expect(ImsReceipt::count())->toBe(2);
});

test('another branch may import the same report period', function () {
    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '5'))->assertRedirect();

    $other = labManagerUser(['lab_id' => makeLab()->id]);
    ($this->importAs)($other, samePeriodPdf('01/10/2026', '31/10/2026', '7'))->assertRedirect();

    expect(ImsReceipt::count())->toBe(2);
});

test('a different custodian code with the same period is accepted', function () {
    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '5', 'E0603'))->assertRedirect();
    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '7', 'E0604'))->assertRedirect();

    expect(ImsReceipt::count())->toBe(2);
});

test('a cancelled document no longer blocks the same period', function () {
    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '5'))->assertRedirect();
    ImsReceipt::firstOrFail()->update(['status' => 'CANCELLED']);

    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026', '7'))->assertRedirect();

    expect(ImsReceipt::count())->toBe(2);
});

test('a manually entered document (no import summary) never blocks an import', function () {
    $this->actingAs($this->manager)->post(route('ims.receipts.store'), ['doc_no' => 'MANUAL'])->assertRedirect();
    expect(ImsReceipt::whereNull('import_summary')->count())->toBe(1);

    ($this->importAs)($this->manager, samePeriodPdf('01/10/2026', '31/10/2026'))->assertRedirect();

    expect(ImsReceipt::count())->toBe(2);
});
