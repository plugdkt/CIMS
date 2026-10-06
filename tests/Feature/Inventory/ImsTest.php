<?php

use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsMovement;
use App\Models\ImsReceipt;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Role;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->lab = makeLab();
    $this->otherLab = makeLab();
    $this->location = makeLocationForLab($this->lab);
    $this->otherLocation = makeLocationForLab($this->otherLab);

    $this->manager = labManagerUser(['lab_id' => $this->lab->id]);
    $this->gram = Unit::where('code', 'g')->firstOrFail();
    $this->ml = Unit::where('code', 'mL')->firstOrFail();

    $this->item = Item::create([
        'item_code' => 'AS100001',
        'name_th' => 'โซเดียมคลอไรด์ทดสอบ',
        'category_id' => ItemCategory::first()->id ?? 1,
        'base_unit_id' => $this->gram->id,
        'package_unit_id' => $this->gram->id,
        'package_size' => '500.000000',
        'storage_class' => 'OTHER',
        'reorder_point_base' => '0.000000',
        'is_active' => true,
    ]);

    // A confirmed 1000 g lot in the manager's own branch.
    $this->makeLot = function (?Item $item = null, string $qty = '1000', array $receiptOverrides = []) {
        $item ??= $this->item;
        $receipt = ImsReceipt::create(array_merge([
            'lab_id' => $this->lab->id,
            'doc_no' => 'PO-69-0001',
            'fiscal_year' => 2569,
            'purchase_round' => 'รอบ 1',
            'status' => 'DRAFT',
            'created_by' => $this->manager->id,
        ], $receiptOverrides));
        $receipt->lines()->create([
            'line_no' => 1,
            'item_id' => $item->id,
            'item_code_raw' => $item->item_code,
            'lot_no' => 'LOT-A1',
            'qty' => $qty,
            'unit_id' => $this->gram->id,
            'unit_price' => '125.5000',
            'expiry_date' => '2571-01-31',
        ]);
        app(\App\Domain\Inventory\Services\ImsService::class)->confirm($receipt, $this->manager);

        return ImsLot::where('ims_receipt_id', $receipt->id)->firstOrFail();
    };
});

function transferPayload(array $overrides = []): array
{
    return array_merge([
        'ims_doc_no' => 'WD-69-0042',
        'tracking_type' => 'BULK',
        'qty' => '300',
        'unit_id' => Unit::where('code', 'g')->firstOrFail()->id,
        'location_id' => test()->location->id,
    ], $overrides);
}

test('guests are redirected and non-managers are forbidden on every IMS page', function () {
    $this->get(route('ims.lots.index'))->assertRedirect(route('login'));
    $this->get(route('ims.receipts.index'))->assertRedirect(route('login'));

    $scientist = scientistUser(['lab_id' => $this->lab->id]);
    $this->actingAs($scientist)->get(route('ims.lots.index'))->assertForbidden();
    $this->actingAs($scientist)->get(route('ims.receipts.create'))->assertForbidden();
});

test('a warehouse manager creates a draft receipt in their own branch, with the source PDF kept privately', function () {
    Storage::fake('local');

    $response = $this->actingAs($this->manager)->post(route('ims.receipts.store'), [
        'doc_no' => 'PO-69-0099',
        'fiscal_year' => 2569,
        'purchase_round' => 'รอบ 2',
        'pdf' => UploadedFile::fake()->create('po.pdf', 120, 'application/pdf'),
    ]);

    $receipt = ImsReceipt::firstOrFail();
    $response->assertRedirect(route('ims.receipts.show', $receipt));
    expect($receipt->lab_id)->toBe($this->lab->id)
        ->and($receipt->status)->toBe('DRAFT')
        ->and($receipt->fiscal_year)->toBe(2569)
        ->and($receipt->source_file_name)->toBe('po.pdf');
    Storage::disk('local')->assertExists($receipt->source_file_path);
});

test('a non-PDF upload is rejected', function () {
    Storage::fake('local');

    $this->actingAs($this->manager)->post(route('ims.receipts.store'), [
        'pdf' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
    ])->assertSessionHasErrors('pdf');

    expect(ImsReceipt::count())->toBe(0);
});

test('lines are added by catalog item code; an unknown code is refused', function () {
    $receipt = ImsReceipt::create(['lab_id' => $this->lab->id, 'status' => 'DRAFT', 'created_by' => $this->manager->id]);

    $this->actingAs($this->manager)->post(route('ims.receipts.lines.store', $receipt), [
        'item_code' => 'AS100001', 'lot_no' => 'L1', 'qty' => '500', 'unit_id' => $this->gram->id, 'unit_price' => '80',
    ])->assertRedirect(route('ims.receipts.show', $receipt));

    $this->actingAs($this->manager)->post(route('ims.receipts.lines.store', $receipt), [
        'item_code' => 'NOPE', 'qty' => '1', 'unit_id' => $this->gram->id,
    ])->assertSessionHasErrors('item_code');

    expect($receipt->lines()->count())->toBe(1);
});

test('confirming turns every line into a lot with its price, lot, purchase year and round, plus a RECEIVE movement', function () {
    $lot = ($this->makeLot)();

    expect($lot->lab_id)->toBe($this->lab->id)
        ->and($lot->doc_no)->toBe('PO-69-0001')
        ->and($lot->fiscal_year)->toBe(2569)
        ->and($lot->purchase_round)->toBe('รอบ 1')
        ->and($lot->lot_no)->toBe('LOT-A1')
        ->and((float) $lot->unit_price)->toEqual(125.5)
        ->and((float) $lot->qty_remaining_base)->toEqual(1000.0);

    $movement = ImsMovement::where('ims_lot_id', $lot->id)->firstOrFail();
    expect($movement->type)->toBe('RECEIVE')->and((float) $movement->balance_after_base)->toEqual(1000.0);

    expect(ImsReceipt::firstOrFail()->status)->toBe('CONFIRMED');
});

test('a confirmed receipt can no longer be edited, and cannot be confirmed twice', function () {
    $lot = ($this->makeLot)();
    $receipt = ImsReceipt::findOrFail($lot->ims_receipt_id);

    $this->actingAs($this->manager)->post(route('ims.receipts.lines.store', $receipt), [
        'item_code' => 'AS100001', 'qty' => '1', 'unit_id' => $this->gram->id,
    ])->assertForbidden();

    $this->actingAs($this->manager)->post(route('ims.receipts.confirm', $receipt))->assertForbidden();
    expect(ImsLot::count())->toBe(1);
});

test('confirming an empty receipt is refused', function () {
    $receipt = ImsReceipt::create(['lab_id' => $this->lab->id, 'status' => 'DRAFT', 'created_by' => $this->manager->id]);

    $this->actingAs($this->manager)->post(route('ims.receipts.confirm', $receipt))->assertSessionHasErrors('confirm');
    expect($receipt->fresh()->status)->toBe('DRAFT');
});

test('the first confirmed receipt adopts the unit it came in, overriding a guessed catalog unit (user-reported 2026-09-22)', function () {
    $litre = Unit::where('code', 'L')->firstOrFail();
    $imported = Item::create([
        'item_code' => 'AS200001', 'name_th' => 'สารทดสอบหน่วย 1 L /ขวด',
        'category_id' => ItemCategory::first()->id ?? 1, 'base_unit_id' => $litre->id,
        'storage_class' => 'OTHER', 'reorder_point_base' => '2.000000', 'is_active' => true,
    ]);

    $receipt = ImsReceipt::create(['lab_id' => $this->lab->id, 'status' => 'DRAFT', 'created_by' => $this->manager->id]);
    $receipt->lines()->create(['line_no' => 1, 'item_id' => $imported->id, 'qty' => '500', 'unit_id' => $this->ml->id]);
    app(\App\Domain\Inventory\Services\ImsService::class)->confirm($receipt, $this->manager);

    expect($imported->fresh()->base_unit_id)->toBe($this->ml->id)
        ->and((float) $imported->fresh()->reorder_point_base)->toEqual(2000.0)
        ->and((float) ImsLot::where('item_id', $imported->id)->firstOrFail()->qty_received_base)->toEqual(500.0);
});

test('once an item has an IMS lot its base unit is frozen', function () {
    ($this->makeLot)();
    $kg = Unit::where('code', 'kg')->firstOrFail();

    $receipt = ImsReceipt::create(['lab_id' => $this->lab->id, 'status' => 'DRAFT', 'created_by' => $this->manager->id]);
    $receipt->lines()->create(['line_no' => 1, 'item_id' => $this->item->id, 'qty' => '2', 'unit_id' => $kg->id]);
    app(\App\Domain\Inventory\Services\ImsService::class)->confirm($receipt, $this->manager);

    expect($this->item->fresh()->base_unit_id)->toBe($this->gram->id);
    // 2 kg is stored as 2000 g, not reinterpreted.
    expect((float) ImsLot::where('ims_receipt_id', $receipt->id)->firstOrFail()->qty_received_base)->toEqual(2000.0);
});

test('transferring to working stock cuts the lot off IMS and creates the container in one step', function () {
    $lot = ($this->makeLot)();

    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), transferPayload())
        ->assertRedirect(route('ims.lots.index'));

    expect((float) $lot->fresh()->qty_remaining_base)->toEqual(700.0);

    $container = Container::where('item_id', $this->item->id)->firstOrFail();
    expect($container->ims_lot_id)->toBe($lot->id)
        ->and($container->lot_no)->toBe('LOT-A1')
        ->and((float) $container->unit_price)->toEqual(125.5)
        ->and((float) $container->remaining_qty_base)->toEqual(300.0)
        ->and($container->expiry_date?->toDateString())->toBe('2571-01-31');

    $ledger = StockLedger::where('item_id', $this->item->id)->firstOrFail();
    expect($ledger->txn_type)->toBe('RECEIVE')
        ->and($ledger->ref_type)->toBe('WORKING_STOCK')
        ->and($ledger->remark)->toBe('WD-69-0042');

    $issue = ImsMovement::where('ims_lot_id', $lot->id)->where('type', 'ISSUE')->firstOrFail();
    expect((float) $issue->qty_base)->toEqual(300.0)
        ->and((float) $issue->balance_after_base)->toEqual(700.0)
        ->and($issue->ref_doc_no)->toBe('WD-69-0042');
});

test('transferring in container mode creates one barcoded bottle each and offers labels', function () {
    $lot = ($this->makeLot)();

    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), transferPayload([
        'tracking_type' => 'CONTAINER', 'container_count' => 2, 'qty_per_container' => '250', 'qty' => null,
    ]))->assertRedirect(route('ims.lots.index'))->assertSessionHas('label_container_ids');

    $containers = Container::where('item_id', $this->item->id)->get();
    expect($containers)->toHaveCount(2)
        ->and($containers->every(fn ($c) => $c->status === 'SEALED' && str_starts_with($c->barcode, 'WS-')))->toBeTrue()
        ->and((float) $lot->fresh()->qty_remaining_base)->toEqual(500.0);
});

test('a transfer larger than the lot balance is refused and writes nothing', function () {
    $lot = ($this->makeLot)();

    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), transferPayload(['qty' => '1500']))
        ->assertSessionHasErrors('transfer');

    expect(Container::count())->toBe(0)
        ->and(StockLedger::count())->toBe(0)
        ->and((float) $lot->fresh()->qty_remaining_base)->toEqual(1000.0);
});

test('a transfer into another branch\'s location is refused', function () {
    $lot = ($this->makeLot)();

    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), transferPayload(['location_id' => $this->otherLocation->id]))
        ->assertSessionHasErrors('transfer');

    expect(Container::count())->toBe(0);
});

test('the source withdrawal document number is required for a transfer', function () {
    $lot = ($this->makeLot)();

    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), transferPayload(['ims_doc_no' => '']))
        ->assertSessionHasErrors('ims_doc_no');
});

test('branch scoping: another branch\'s manager cannot see or transfer this branch\'s lots', function () {
    $lot = ($this->makeLot)();
    $outsider = labManagerUser(['lab_id' => $this->otherLab->id]);

    $this->actingAs($outsider)->get(route('ims.lots.index'))->assertOk()->assertDontSee('AS100001');
    $this->actingAs($outsider)->get(route('ims.lots.transfer.form', $lot))->assertForbidden();
    $this->actingAs($outsider)->post(route('ims.lots.transfer', $lot), transferPayload())->assertForbidden();
    $this->actingAs($outsider)->get(route('ims.receipts.show', ImsReceipt::firstOrFail()))->assertForbidden();
});

test('ADMIN can read every branch\'s IMS stock but cannot confirm or transfer (spec §3: no ledger writes)', function () {
    $lot = ($this->makeLot)();
    $admin = User::factory()->create();
    $admin->roles()->attach(Role::where('code', 'ADMIN')->firstOrFail());

    $this->actingAs($admin)->get(route('ims.lots.index'))->assertOk()->assertSee('AS100001');
    $this->actingAs($admin)->get(route('ims.receipts.create'))->assertForbidden();
    $this->actingAs($admin)->post(route('ims.lots.transfer', $lot), transferPayload())->assertForbidden();
});

test('an emptied lot offers no further transfer', function () {
    $lot = ($this->makeLot)(null, '100');

    $this->actingAs($this->manager)->post(route('ims.lots.transfer', $lot), transferPayload(['qty' => '100']))->assertRedirect();
    $this->actingAs($this->manager)->get(route('ims.lots.transfer.form', $lot))->assertForbidden();
});

test('every IMS page renders for a warehouse manager', function () {
    $lot = ($this->makeLot)();
    $receipt = ImsReceipt::findOrFail($lot->ims_receipt_id);
    $draft = ImsReceipt::create(['lab_id' => $this->lab->id, 'doc_no' => 'DRAFT-1', 'status' => 'DRAFT', 'created_by' => $this->manager->id]);

    $this->actingAs($this->manager);
    $this->get(route('ims.receipts.index'))->assertOk()->assertSee('PO-69-0001');
    $this->get(route('ims.receipts.create'))->assertOk();
    $this->get(route('ims.receipts.show', $receipt))->assertOk()->assertSee('AS100001');
    $this->get(route('ims.receipts.show', $draft))->assertOk();
    $this->get(route('ims.lots.index'))->assertOk()->assertSee('LOT-A1');
    $this->get(route('ims.lots.transfer.form', $lot))->assertOk();
});

test('the IMS lot list can be filtered by chemical type', function () {
    $media = ItemCategory::where('code', 'MEDIA')->firstOrFail();
    $agar = Item::create([
        'item_code' => 'AS300001', 'name_th' => 'วุ้นเลี้ยงเชื้อทดสอบ', 'category_id' => $media->id,
        'base_unit_id' => $this->gram->id, 'storage_class' => 'OTHER', 'reorder_point_base' => '0.000000', 'is_active' => true,
    ]);
    ($this->makeLot)();
    ($this->makeLot)($agar, '200', ['doc_no' => 'PO-69-0002']);

    $this->actingAs($this->manager)->get(route('ims.lots.index', ['category' => $media->id]))
        ->assertOk()->assertSee('AS300001')->assertDontSee('AS100001');
});

test('all eight chemical types exist as item categories', function () {
    expect(ItemCategory::where('is_chemical', true)->pluck('name_th')->sort()->values()->all())
        ->toEqualCanonicalizing(['สารเคมี', 'อาหารเลี้ยงเชื้อ', 'สี', 'น้ำตาล', 'ยาปฏิชีวนะ', 'Test Kits', 'Detergent', 'อื่นๆ']);
});
