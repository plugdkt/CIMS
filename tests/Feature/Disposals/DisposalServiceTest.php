<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InvalidDisposalException;
use App\Domain\Inventory\Services\DisposalService;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('FR-ST-05: requesting a disposal creates a PENDING row with a real doc_no', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    $disposal = app(DisposalService::class)->request($container->fresh(), '20.000000', 'EXPIRED', 'เผาทำลาย', now()->toDateString(), $user);

    expect($disposal->doc_no)->toStartWith('DSP-');
    expect($disposal->status)->toBe('PENDING');
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000'); // nothing written to the ledger yet
});

test('requesting more than the container holds is rejected', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $user = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '10.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $user->id));

    expect(fn () => app(DisposalService::class)->request($container->fresh(), '20.000000', 'DAMAGED', null, now()->toDateString(), $user))
        ->toThrow(InvalidDisposalException::class);
});

test('approving writes a DISPOSE ledger row and marks the disposal APPROVED', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id));

    $service = app(DisposalService::class);
    $disposal = $service->request($container->fresh(), '20.000000', 'CONTAMINATED', 'ส่งบริษัทกำจัดของเสีย', now()->toDateString(), $requester);

    $approved = $service->approve($disposal, $approver);

    expect($approved->status)->toBe('APPROVED');
    expect($approved->approved_by)->toBe($approver->id);
    expect($container->fresh()->remaining_qty_base)->toBe('30.000000');
    $row = StockLedger::where('item_id', $item->id)->where('txn_type', 'DISPOSE')->first();
    expect($row)->not->toBeNull();
    expect($row->qty_out_base)->toBe('20.000000');
    expect($row->ref_doc_no)->toBe($disposal->doc_no);
});

test('approving re-checks against the container\'s current remaining stock, not just the original request', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id));

    $service = app(DisposalService::class);
    $disposal = $service->request($container->fresh(), '40.000000', 'WASTE', null, now()->toDateString(), $requester);

    // stock moved elsewhere between request and approval
    app(LedgerService::class)->issue($container->fresh()->id, '30.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id));

    expect(fn () => $service->approve($disposal, $approver))
        ->toThrow(InvalidDisposalException::class);
});

test('rejecting a disposal does not touch the ledger', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id));

    $service = app(DisposalService::class);
    $disposal = $service->request($container->fresh(), '20.000000', 'OTHER', null, now()->toDateString(), $requester);

    $rejected = $service->reject($disposal, $approver);

    expect($rejected->status)->toBe('REJECTED');
    expect($container->fresh()->remaining_qty_base)->toBe('50.000000');
    expect(StockLedger::where('item_id', $item->id)->where('txn_type', 'DISPOSE')->count())->toBe(0);
});

test('a disposal that is not PENDING cannot be approved or rejected again', function () {
    $item = makeItem();
    $g = Unit::where('code', 'g')->firstOrFail();
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer(['item_id' => $item->id]);
    app(LedgerService::class)->receive($container->id, '50.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $requester->id));

    $service = app(DisposalService::class);
    $disposal = $service->request($container->fresh(), '20.000000', 'EXPIRED', null, now()->toDateString(), $requester);
    $service->approve($disposal, $approver);

    expect(fn () => $service->approve($disposal->fresh(), $approver))->toThrow(InvalidDisposalException::class);
    expect(fn () => $service->reject($disposal->fresh(), $approver))->toThrow(InvalidDisposalException::class);
});
