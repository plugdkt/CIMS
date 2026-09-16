<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Requisition\Exceptions\ExcessiveIssueQuantityException;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\IssueService;
use App\Domain\Requisition\Services\RequisitionService;
use App\Models\Container;
use App\Models\Requisition;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const TEST_SIGNATURE_HASH = 'a1b2c3';

if (! function_exists('approvedRequisition')) {
    /** An APPROVED requisition with one line requesting $qtyRequested grams of a fresh item. */
    function approvedRequisition(User $requester, string $qtyRequested = '100.000000'): Requisition
    {
        $requisition = makeRequisition($requester);
        $item = makeItem();
        $g = Unit::where('code', 'g')->firstOrFail();
        app(RequisitionService::class)->addLine($requisition, $item, $g, $qtyRequested);
        $requisition->update(['status' => 'APPROVED']);

        return $requisition->fresh(['items']);
    }
}

if (! function_exists('stockedContainer')) {
    function stockedContainer(int $itemId, string $qtyBase, User $receiver): Container
    {
        $container = makeContainer(['item_id' => $itemId, 'remaining_qty_base' => '0.000000']);
        $g = Unit::where('code', 'g')->firstOrFail();
        app(LedgerService::class)->receive($container->id, $qtyBase, new LedgerEntryData(displayUnitId: $g->id, createdBy: $receiver->id));

        return $container->fresh();
    }
}

test('issuing the exact requested quantity from one container marks the requisition ISSUED', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    $issue = app(IssueService::class)->issue($line, $container, '50.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH);

    expect($issue->qty_issued_base)->toBe('50.000000');
    expect($issue->signature_hash)->toBe(TEST_SIGNATURE_HASH);
    expect($line->fresh()->qty_issued_base)->toBe('50.000000');
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
    expect($requisition->fresh()->status)->toBe('ISSUED');
    expect($requisition->fresh()->completed_at)->not->toBeNull();
});

test('FR-RQ-10: a single line can be issued from two containers, summing toward the request', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '800.000000');
    $line = $requisition->items->first();
    $bottleA = stockedContainer($line->item_id, '500.000000', $staff);
    $bottleB = stockedContainer($line->item_id, '500.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();
    $service = app(IssueService::class);

    $service->issue($line, $bottleA, '500.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH);
    expect($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED');

    $service->issue($line->fresh(), $bottleB, '300.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH);

    $freshLine = $line->fresh();
    expect($freshLine->qty_issued_base)->toBe('800.000000');
    expect($bottleA->fresh()->remaining_qty_base)->toBe('0.000000');
    expect($bottleB->fresh()->remaining_qty_base)->toBe('200.000000');
    expect($requisition->fresh()->status)->toBe('ISSUED');
});

test('a partial issue (less than requested) leaves the requisition PARTIALLY_ISSUED', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '100.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    app(IssueService::class)->issue($line, $container, '60.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH);

    expect($requisition->fresh()->status)->toBe('PARTIALLY_ISSUED');
});

test('BR-04: issuing up to 10% over the requested quantity requires only a remark', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '200.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    expect(fn () => app(IssueService::class)->issue($line, $container, '108.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH))
        ->toThrow(ExcessiveIssueQuantityException::class);

    $issue = app(IssueService::class)->issue($line, $container, '108.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH, null, 'ชั่งได้เท่านี้ตามสภาพขวด');
    expect($issue->qty_issued_base)->toBe('108.000000');
});

test('BR-04: issuing more than 10% over requires an approver holding requisition.issue_override', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '200.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    expect(fn () => app(IssueService::class)->issue($line, $container, '115.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH, null, 'ของเหลือน้อยจึงจ่ายทั้งขวด'))
        ->toThrow(ExcessiveIssueQuantityException::class);

    $notLabManager = User::factory()->create();
    expect(fn () => app(IssueService::class)->issue($line, $container, '115.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH, null, 'ของเหลือน้อยจึงจ่ายทั้งขวด', $notLabManager->id))
        ->toThrow(ExcessiveIssueQuantityException::class);

    $labManager = labManagerUser(['lab_id' => $requisition->lab_id]);
    $issue = app(IssueService::class)->issue($line, $container, '115.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH, null, 'ของเหลือน้อยจึงจ่ายทั้งขวด', $labManager->id);

    expect($issue->qty_issued_base)->toBe('115.000000');
    expect($line->fresh()->overage_approved_by)->toBe($labManager->id);
});

test('BR-04 + branch scoping: a LAB_MANAGER of a different branch cannot approve an overage issue', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '100.000000');
    $line = $requisition->items->first();
    $container = stockedContainer($line->item_id, '200.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();
    $otherLab = makeLab();
    $labManager = labManagerUser(['lab_id' => $otherLab->id]);

    expect(fn () => app(IssueService::class)->issue($line, $container, '115.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH, null, 'ของเหลือน้อยจึงจ่ายทั้งขวด', $labManager->id))
        ->toThrow(ExcessiveIssueQuantityException::class);
});

test('a container belonging to a different item is rejected', function () {
    $staff = staffUser();
    $requisition = approvedRequisition($staff, '50.000000');
    $line = $requisition->items->first();
    $otherItem = makeItem();
    $container = stockedContainer($otherItem->id, '50.000000', $staff);
    $scientist = scientistUser();
    $g = Unit::where('code', 'g')->firstOrFail();

    expect(fn () => app(IssueService::class)->issue($line, $container, '10.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH))
        ->toThrow(InvalidRequisitionTransitionException::class);
});

test('issuing against a requisition that is not APPROVED/PARTIALLY_ISSUED is rejected', function () {
    $staff = staffUser();
    $requisition = makeRequisition($staff); // DRAFT
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $line = app(RequisitionService::class)->addLine($requisition, $item, $g, '10.000000');
    $container = stockedContainer($item->id, '50.000000', $staff);
    $scientist = scientistUser();

    expect(fn () => app(IssueService::class)->issue($line, $container, '10.000000', $g, $scientist, $staff, TEST_SIGNATURE_HASH))
        ->toThrow(InvalidRequisitionTransitionException::class);
});
