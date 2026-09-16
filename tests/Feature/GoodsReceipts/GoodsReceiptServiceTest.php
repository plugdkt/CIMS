<?php

use App\Domain\Inventory\Exceptions\InvalidGoodsReceiptStateException;
use App\Domain\Inventory\Exceptions\MissingDensityException;
use App\Domain\Inventory\Services\GoodsReceiptService;
use App\Domain\Inventory\Services\LedgerHasher;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\Lab;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('makeLab')) {
    function makeLab(array $overrides = []): Lab
    {
        return Lab::create(array_merge([
            'code' => 'LAB-'.fake()->unique()->numerify('####'),
            'name_th' => 'ห้องปฏิบัติการทดสอบ',
            'is_active' => true,
        ], $overrides));
    }
}

function makeDraftGrn(array $overrides = []): GoodsReceipt
{
    return GoodsReceipt::create(array_merge([
        'doc_no' => 'GRN-2569-'.fake()->unique()->numerify('#####'),
        'receipt_date' => now()->toDateString(),
        'lab_id' => makeLab()->id,
        'status' => 'DRAFT',
        'received_by' => User::factory()->create()->id,
    ], $overrides));
}

test('calculateLineTotalBase converts container_count x qty_per_container into the item base unit', function () {
    $service = app(GoodsReceiptService::class);
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $kg = Unit::where('code', 'kg')->firstOrFail();

    $total = $service->calculateLineTotalBase($item->fresh(), $kg, '2', '1');

    expect($total)->toBe('2000.000000');
});

test('calculateLineTotalBase crosses dimensions using the item density', function () {
    $service = app(GoodsReceiptService::class);
    $item = makeItem([
        'base_unit_id' => Unit::where('code', 'g')->value('id'),
        'density_g_per_ml' => '0.800000',
    ]);
    $mL = Unit::where('code', 'mL')->firstOrFail();

    $total = $service->calculateLineTotalBase($item->fresh(), $mL, '1', '100');

    expect($total)->toBe('80.000000');
});

test('calculateLineTotalBase throws when crossing dimensions without a density', function () {
    $service = app(GoodsReceiptService::class);
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id'), 'density_g_per_ml' => null]);
    $mL = Unit::where('code', 'mL')->firstOrFail();

    expect(fn () => $service->calculateLineTotalBase($item->fresh(), $mL, '1', '100'))
        ->toThrow(MissingDensityException::class);
});

test('addLine assigns sequential line numbers and stores the computed base total', function () {
    $service = app(GoodsReceiptService::class);
    $grn = makeDraftGrn();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $line1 = $service->addLine($grn, $item, $g, 2, '10');
    $line2 = $service->addLine($grn, $item, $g, 1, '5');

    expect($line1->line_no)->toBe(1);
    expect($line2->line_no)->toBe(2);
    expect($line1->qty_total_base)->toBe('20.000000');
    expect($line2->qty_total_base)->toBe('5.000000');
});

test('addLine rejects a non-DRAFT goods receipt', function () {
    $service = app(GoodsReceiptService::class);
    $grn = makeDraftGrn(['status' => 'CANCELLED']);
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();

    expect(fn () => $service->addLine($grn, $item, $g, 1, '10'))
        ->toThrow(InvalidGoodsReceiptStateException::class);
});

test('confirm creates one container per unit and writes one RECEIVE ledger row each (FR-RC-02/05)', function () {
    $service = app(GoodsReceiptService::class);
    $confirmer = User::factory()->create();
    $grn = makeDraftGrn();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();

    $service->addLine($grn, $item, $g, 3, '10', ['lot_no' => 'LOT-1', 'expiry_date' => '2027-01-01']);

    $confirmed = $service->confirm($grn->fresh(), $confirmer->id);

    expect($confirmed->status)->toBe('CONFIRMED');
    expect($confirmed->confirmed_at)->not->toBeNull();

    $containers = Container::where('item_id', $item->id)->get();
    expect($containers)->toHaveCount(3);
    foreach ($containers as $container) {
        expect($container->remaining_qty_base)->toBe('10.000000');
        expect($container->initial_qty_base)->toBe('10.000000');
        expect($container->lot_no)->toBe('LOT-1');
        expect($container->status)->toBe('SEALED');
    }
    expect($containers->pluck('barcode')->unique())->toHaveCount(3);

    $ledgerRows = StockLedger::where('item_id', $item->id)->orderBy('id')->get();
    expect($ledgerRows)->toHaveCount(3);
    expect($ledgerRows->pluck('txn_type')->unique()->all())->toBe(['RECEIVE']);
    expect($ledgerRows->last()->balance_base)->toBe('30.000000');

    $chain = app(LedgerHasher::class)->verifyChain($item->id);
    expect($chain['ok'])->toBeTrue();
});

test('confirm rejects a GRN with no lines', function () {
    $service = app(GoodsReceiptService::class);
    $grn = makeDraftGrn();

    expect(fn () => $service->confirm($grn, User::factory()->create()->id))
        ->toThrow(InvalidGoodsReceiptStateException::class);
});

test('confirm rejects a GRN that is not DRAFT', function () {
    $service = app(GoodsReceiptService::class);
    $grn = makeDraftGrn(['status' => 'CONFIRMED']);

    expect(fn () => $service->confirm($grn, User::factory()->create()->id))
        ->toThrow(InvalidGoodsReceiptStateException::class);
});

test('cancel only works on a DRAFT goods receipt (FR-RC-06)', function () {
    $service = app(GoodsReceiptService::class);
    $draft = makeDraftGrn();
    $confirmed = makeDraftGrn(['status' => 'CONFIRMED']);

    $cancelled = $service->cancel($draft);
    expect($cancelled->status)->toBe('CANCELLED');

    expect(fn () => $service->cancel($confirmed))
        ->toThrow(InvalidGoodsReceiptStateException::class);
});
