<?php

use App\Models\ImsReceipt;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->lab = makeLab();
    $this->manager = labManagerUser(['lab_id' => $this->lab->id]);
    $gram = Unit::where('code', 'g')->firstOrFail();
    $this->item = Item::create([
        'item_code' => 'AS200001', 'name_th' => 'สารทดสอบ AS200001', 'category_id' => ItemCategory::first()->id ?? 1,
        'base_unit_id' => $gram->id, 'package_unit_id' => $gram->id, 'package_size' => '500.000000',
        'storage_class' => 'OTHER', 'reorder_point_base' => '0.000000', 'is_active' => true,
    ]);

    // A document that came from the PDF importer (import_summary set) with one line.
    $this->pdfReceipt = fn (array $attrs = []) => ImsReceipt::create($attrs + [
        'lab_id' => $this->lab->id, 'status' => 'DRAFT', 'created_by' => $this->manager->id,
        'import_summary' => ['rows_total' => 1, 'imported' => 1, 'no_stock' => 0, 'unmatched' => [], 'unbalanced' => []],
    ]);
    $this->addLine = fn (ImsReceipt $r) => $r->lines()->create([
        'line_no' => 1, 'item_id' => $this->item->id, 'item_code_raw' => 'AS200001', 'name_raw' => 'x', 'pack_qty' => '2', 'unit_price' => '10',
    ]);
    $this->confirmLine = fn ($line) => $this->actingAs($this->manager)
        ->post(route('ims.receipts.confirm', ImsReceipt::findOrFail($line->ims_receipt_id)), ['lines' => [$line->id]]);
});

test('no warning on the create page when the branch has not confirmed any PDF import', function () {
    $this->actingAs($this->manager)->get(route('ims.receipts.create'))->assertOk()->assertDontSee('double-count-warning');

    // An unconfirmed PDF draft does not count.
    ($this->addLine)(($this->pdfReceipt)());
    $this->actingAs($this->manager)->get(route('ims.receipts.create'))->assertOk()->assertDontSee('double-count-warning');
});

test('the create page warns once a PDF-imported line was confirmed in the branch', function () {
    $receipt = ($this->pdfReceipt)();
    $line = ($this->addLine)($receipt);
    ($this->confirmLine)($line)->assertRedirect();

    $this->actingAs($this->manager)->get(route('ims.receipts.create'))
        ->assertOk()->assertSee('double-count-warning')->assertSee(__('ims.double_count_title'));
});

test('a manually entered confirmed document does not trigger the warning', function () {
    $receipt = ($this->pdfReceipt)(['import_summary' => null]);
    $line = ($this->addLine)($receipt);
    ($this->confirmLine)($line)->assertRedirect();

    $this->actingAs($this->manager)->get(route('ims.receipts.create'))->assertOk()->assertDontSee('double-count-warning');
});

test('a cancelled document and another branch do not trigger the warning', function () {
    $receipt = ($this->pdfReceipt)();
    $line = ($this->addLine)($receipt);
    ($this->confirmLine)($line)->assertRedirect();

    // A real second branch: it has no confirmed PDF import of its own, so the first branch's one must not count.
    $otherLab = makeLab();
    expect(ImsReceipt::confirmedPdfImportCount($otherLab->id))->toBe(0);
    expect(ImsReceipt::confirmedPdfImportCount($this->lab->id))->toBe(1);

    $receipt->update(['status' => 'CANCELLED']);
    expect(ImsReceipt::confirmedPdfImportCount($this->lab->id))->toBe(0);
});

test('the show page of a manually entered document shows no warning', function () {
    $first = ($this->pdfReceipt)();
    ($this->confirmLine)(($this->addLine)($first))->assertRedirect();

    $manual = ($this->pdfReceipt)(['import_summary' => null]);
    ($this->addLine)($manual);

    $this->actingAs($this->manager)->get(route('ims.receipts.show', $manual))
        ->assertOk()->assertDontSee('double-count-warning');
});

test('the show page of a CONFIRMED document shows no warning', function () {
    $first = ($this->pdfReceipt)();
    ($this->confirmLine)(($this->addLine)($first))->assertRedirect();

    $second = ($this->pdfReceipt)();
    ($this->confirmLine)(($this->addLine)($second))->assertRedirect();
    expect($second->fresh()->status)->toBe('CONFIRMED');

    $this->actingAs($this->manager)->get(route('ims.receipts.show', $second))
        ->assertOk()->assertDontSee('double-count-warning');
});

test('the review page of a second PDF draft warns, but not about its own confirmed lines', function () {
    $first = ($this->pdfReceipt)();
    ($this->confirmLine)(($this->addLine)($first))->assertRedirect();

    $second = ($this->pdfReceipt)();
    ($this->addLine)($second);

    $this->actingAs($this->manager)->get(route('ims.receipts.show', $second))
        ->assertOk()->assertSee('double-count-warning');

    // The first document's own page: nothing else was confirmed, so no warning.
    expect(ImsReceipt::confirmedPdfImportCount($this->lab->id, $first->id))->toBe(0);
});
