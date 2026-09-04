<?php

use App\Domain\Requisition\Exceptions\InvalidReturnException;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\ReturnService;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FT-05: returning 20 mL from 50 mL issued writes a RETURN row and credits the container', function () {
    $staff = staffUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'mL')->value('id')]);
    $requisition = makeRequisition($staff);
    $mL = Unit::where('code', 'mL')->firstOrFail();
    app(\App\Domain\Requisition\Services\RequisitionService::class)->addLine($requisition, $item, $mL, '50');
    $requisition->update(['status' => 'APPROVED']);

    $container = stockedContainer($item->id, '200.000000', $staff);
    $line = $requisition->fresh(['items'])->items->first();
    $scientist = scientistUser();
    app(IssueService::class)->issue($line, $container, '50', $mL, $scientist, $staff, hash('sha256', 'ft-05'));

    $row = app(ReturnService::class)->return($line->fresh(), $container->fresh(), '20', $mL, $scientist);

    expect($row->txn_type)->toBe('RETURN');
    expect($container->fresh()->remaining_qty_base)->toBe('170.000000');
    expect($line->fresh()->qty_returned_base)->toBe('20.000000');
});

test('BR-05: returning more than was issued is rejected', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();
    app(IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));

    expect(fn () => app(ReturnService::class)->return($line->fresh(), $container->fresh(), '60.000000', $g, $scientist))
        ->toThrow(InvalidReturnException::class);
});

test('BR-05: a line with nothing issued yet has nothing to return', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    expect(fn () => app(ReturnService::class)->return($line, $container, '10.000000', $g, $scientist))
        ->toThrow(InvalidReturnException::class);
});

test('BR-05: returning into a container this line was never issued from is rejected', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $issuedContainer = stockedContainer($line->item_id, '100.000000', $staff);
    $otherContainer = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();
    app(IssueService::class)->issue($line, $issuedContainer, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));

    expect(fn () => app(ReturnService::class)->return($line->fresh(), $otherContainer->fresh(), '10.000000', $g, $scientist))
        ->toThrow(InvalidReturnException::class);
});

test('multiple partial returns accumulate correctly against qty_returned_base', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();
    app(IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, hash('sha256', 'x'));
    $returnService = app(ReturnService::class);

    $returnService->return($line->fresh(), $container->fresh(), '10.000000', $g, $scientist);
    $returnService->return($line->fresh(), $container->fresh(), '15.000000', $g, $scientist);

    expect($line->fresh()->qty_returned_base)->toBe('25.000000');
    expect($container->fresh()->remaining_qty_base)->toBe('75.000000');
});
