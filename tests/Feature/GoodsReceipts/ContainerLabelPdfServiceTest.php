<?php

use App\Domain\Inventory\Services\GoodsReceiptService;
use App\Domain\Labeling\Services\ContainerLabelPdfService;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('containersFor traces containers back to the GRN that received them', function () {
    $goodsReceiptService = app(GoodsReceiptService::class);
    $labelService = app(ContainerLabelPdfService::class);
    $confirmer = User::factory()->create();
    $grnA = makeDraftGrn();
    $grnB = makeDraftGrn();
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();

    $goodsReceiptService->addLine($grnA, $item, $g, 2, '10');
    $goodsReceiptService->addLine($grnB, $item, $g, 1, '5');
    $goodsReceiptService->confirm($grnA->fresh(), $confirmer->id);
    $goodsReceiptService->confirm($grnB->fresh(), $confirmer->id);

    $containersA = $labelService->containersFor($grnA->fresh());
    $containersB = $labelService->containersFor($grnB->fresh());

    expect($containersA)->toHaveCount(2);
    expect($containersB)->toHaveCount(1);
    expect($containersA->pluck('id')->intersect($containersB->pluck('id')))->toBeEmpty();
});

test('render produces a non-empty PDF for both label sizes', function () {
    $goodsReceiptService = app(GoodsReceiptService::class);
    $labelService = app(ContainerLabelPdfService::class);
    $confirmer = User::factory()->create();
    $grn = makeDraftGrn();
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();

    $goodsReceiptService->addLine($grn, $item, $g, 3, '10', ['lot_no' => 'LOT-9']);
    $goodsReceiptService->confirm($grn->fresh(), $confirmer->id);

    $containers = $labelService->containersFor($grn->fresh());

    foreach (ContainerLabelPdfService::availableSizes() as $size) {
        $pdf = $labelService->render($containers, $size);
        expect($pdf)->toStartWith('%PDF');
    }
});

test('render rejects an unknown label size', function () {
    $labelService = app(ContainerLabelPdfService::class);
    $grn = makeDraftGrn();

    expect(fn () => $labelService->render($labelService->containersFor($grn), '99x99'))
        ->toThrow(InvalidArgumentException::class);
});
