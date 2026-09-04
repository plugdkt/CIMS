<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidAdjustmentException;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('BR-06: a LAB_MANAGER can adjust up (ADJUST_IN) with a distinct, authorized approver', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    $row = app(AdjustmentService::class)->adjust($container->fresh(), '5.000000', 'พบของเกินจากตรวจนับ', $creator, $approver);

    expect($row->txn_type)->toBe('ADJUST_IN');
    expect($row->qty_in_base)->toBe('5.000000');
    expect($row->approved_by)->toBe($approver->id);
    expect($container->fresh()->remaining_qty_base)->toBe('55.000000');
});

test('BR-06: adjusting down (ADJUST_OUT) reduces the container balance', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    $row = app(AdjustmentService::class)->adjust($container->fresh(), '-5.000000', 'พบของขาดจากตรวจนับ', $creator, $approver);

    expect($row->txn_type)->toBe('ADJUST_OUT');
    expect($row->qty_out_base)->toBe('5.000000');
    expect($container->fresh()->remaining_qty_base)->toBe('45.000000');
});

test('BR-06: the approver must actually hold ledger.adjust, not merely be a different user', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $notAnApprover = User::factory()->create(); // no role, no ledger.adjust
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    expect(fn () => app(AdjustmentService::class)->adjust($container->fresh(), '5.000000', 'พบของเกินจากตรวจนับ', $creator, $notAnApprover))
        ->toThrow(InvalidAdjustmentException::class);
});

test('BR-06: the approver cannot be the same person as the creator', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $labManager = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $labManager->id));

    expect(fn () => app(AdjustmentService::class)->adjust($container->fresh(), '5.000000', 'พบของเกินจากตรวจนับ', $labManager, $labManager))
        ->toThrow(InvalidAdjustmentException::class);
});

test('BR-06: a remark shorter than 10 characters is rejected', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    expect(fn () => app(AdjustmentService::class)->adjust($container->fresh(), '5.000000', 'สั้น', $creator, $approver))
        ->toThrow(InvalidAdjustmentException::class);
});

test('adjusting down more than the remaining stock throws InsufficientStockException', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    expect(fn () => app(AdjustmentService::class)->adjust($container->fresh(), '-20.000000', 'พบของขาดจากตรวจนับ', $creator, $approver))
        ->toThrow(\App\Domain\Inventory\Exceptions\InsufficientStockException::class);
});

test('the ledger row records approved_by, unlike other txn types', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = labManagerUser();
    $approver = labManagerUser();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    app(AdjustmentService::class)->adjust($container->fresh(), '5.000000', 'พบของเกินจากตรวจนับ', $creator, $approver);

    $receiveRow = StockLedger::where('item_id', $item->id)->where('txn_type', 'RECEIVE')->firstOrFail();
    $adjustRow = StockLedger::where('item_id', $item->id)->where('txn_type', 'ADJUST_IN')->firstOrFail();

    expect($receiveRow->approved_by)->toBeNull();
    expect($adjustRow->approved_by)->toBe($approver->id);
});
