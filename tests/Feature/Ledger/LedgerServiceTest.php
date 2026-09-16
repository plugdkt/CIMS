<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidAdjustmentException;
use App\Domain\Inventory\Services\LedgerHasher;
use App\Domain\Inventory\Services\LedgerService;
use App\Models\Container;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

if (! function_exists('makeContainer')) {
    function makeContainer(array $overrides = []): Container
    {
        $item = $overrides['item_id'] ?? null;
        unset($overrides['item_id']);
        $item = $item ?? makeItem()->id;

        return Container::create(array_merge([
            'item_id' => $item,
            'barcode' => 'BC-'.fake()->unique()->numerify('########'),
            'received_at' => now()->toDateString(),
            'initial_qty_base' => '100.000000',
            'remaining_qty_base' => '0.000000',
            'status' => 'SEALED',
        ], $overrides));
    }
}

function ledgerCtx(User $user, array $overrides = []): LedgerEntryData
{
    $unit = Unit::where('code', 'g')->firstOrFail();

    return new LedgerEntryData(
        displayUnitId: $overrides['displayUnitId'] ?? $unit->id,
        createdBy: $overrides['createdBy'] ?? $user->id,
        refType: $overrides['refType'] ?? null,
        refId: $overrides['refId'] ?? null,
        refDocNo: $overrides['refDocNo'] ?? null,
        issuerId: $overrides['issuerId'] ?? null,
        receiverId: $overrides['receiverId'] ?? null,
        receiverName: $overrides['receiverName'] ?? null,
        signatureHash: $overrides['signatureHash'] ?? null,
        remark: $overrides['remark'] ?? null,
        approvedBy: $overrides['approvedBy'] ?? null,
    );
}

test('receive() writes a RECEIVE row and increases the container remaining quantity', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $row = $service->receive($container->id, '25.000000', ledgerCtx($user));

    expect($row->txn_type)->toBe('RECEIVE');
    expect($row->qty_in_base)->toBe('25.000000');
    expect($row->balance_base)->toBe('25.000000');
    expect($row->prev_row_hash)->toBeNull();
    expect($container->fresh()->remaining_qty_base)->toBe('25.000000');
});

test('issue() decreases the container remaining quantity and chains the hash to the previous row', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $received = $service->receive($container->id, '25.000000', ledgerCtx($user));
    $issued = $service->issue($container->id, '10.000000', ledgerCtx($user));

    expect($issued->txn_type)->toBe('ISSUE');
    expect($issued->qty_out_base)->toBe('10.000000');
    expect($issued->balance_base)->toBe('15.000000');
    expect($issued->prev_row_hash)->toBe($received->row_hash);
    expect($container->fresh()->remaining_qty_base)->toBe('15.000000');
});

test('issue() sets the container to EMPTY once fully consumed and stamps opened_at', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '10.000000', ledgerCtx($user));
    $service->issue($container->id, '10.000000', ledgerCtx($user));

    $fresh = $container->fresh();
    expect($fresh->remaining_qty_base)->toBe('0.000000');
    expect($fresh->status)->toBe('EMPTY');
    expect($fresh->opened_at)->not->toBeNull();
});

test('issue() rejects a quantity larger than what remains in the container', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '5.000000', ledgerCtx($user));

    expect(fn () => $service->issue($container->id, '10.000000', ledgerCtx($user)))
        ->toThrow(InsufficientStockException::class);

    expect($container->fresh()->remaining_qty_base)->toBe('5.000000');
});

test('return() puts stock back into the same container and reopens it if it was EMPTY (BR-05)', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '10.000000', ledgerCtx($user));
    $service->issue($container->id, '10.000000', ledgerCtx($user));
    expect($container->fresh()->status)->toBe('EMPTY');

    $returned = $service->return($container->id, '4.000000', ledgerCtx($user));

    expect($returned->txn_type)->toBe('RETURN');
    expect($returned->balance_base)->toBe('4.000000');
    $fresh = $container->fresh();
    expect($fresh->remaining_qty_base)->toBe('4.000000');
    expect($fresh->status)->toBe('IN_USE');
});

test('adjust() with a positive quantity writes ADJUST_IN and increases the balance (BR-06)', function () {
    $service = app(LedgerService::class);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '10.000000', ledgerCtx($creator));
    $row = $service->adjust($container->id, '3.000000', ledgerCtx($creator, [
        'remark' => 'พบส่วนต่างจากการตรวจนับประจำเดือน',
        'approvedBy' => $approver->id,
    ]));

    expect($row->txn_type)->toBe('ADJUST_IN');
    expect($row->qty_in_base)->toBe('3.000000');
    expect($row->balance_base)->toBe('13.000000');
    expect($container->fresh()->remaining_qty_base)->toBe('13.000000');
});

test('adjust() with a negative quantity writes ADJUST_OUT and decreases the balance (BR-06)', function () {
    $service = app(LedgerService::class);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '10.000000', ledgerCtx($creator));
    $row = $service->adjust($container->id, '-4.000000', ledgerCtx($creator, [
        'remark' => 'พบส่วนต่างจากการตรวจนับประจำเดือน',
        'approvedBy' => $approver->id,
    ]));

    expect($row->txn_type)->toBe('ADJUST_OUT');
    expect($row->qty_out_base)->toBe('4.000000');
    expect($row->balance_base)->toBe('6.000000');
    expect($container->fresh()->remaining_qty_base)->toBe('6.000000');
});

test('adjust() rejects a remark shorter than 10 characters (BR-06)', function () {
    $service = app(LedgerService::class);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer();
    $service->receive($container->id, '10.000000', ledgerCtx($creator));

    expect(fn () => $service->adjust($container->id, '1.000000', ledgerCtx($creator, [
        'remark' => 'สั้นไป',
        'approvedBy' => $approver->id,
    ])))->toThrow(InvalidAdjustmentException::class);
});

test('adjust() rejects an approver who is the same person as the creator (BR-06)', function () {
    $service = app(LedgerService::class);
    $creator = User::factory()->create();
    $container = makeContainer();
    $service->receive($container->id, '10.000000', ledgerCtx($creator));

    expect(fn () => $service->adjust($container->id, '1.000000', ledgerCtx($creator, [
        'remark' => 'พบส่วนต่างจากการตรวจนับประจำเดือน',
        'approvedBy' => $creator->id,
    ])))->toThrow(InvalidAdjustmentException::class);
});

test('adjust() rejects a decrease that would push the container balance negative', function () {
    $service = app(LedgerService::class);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer();
    $service->receive($container->id, '5.000000', ledgerCtx($creator));

    expect(fn () => $service->adjust($container->id, '-10.000000', ledgerCtx($creator, [
        'remark' => 'พบส่วนต่างจากการตรวจนับประจำเดือน',
        'approvedBy' => $approver->id,
    ])))->toThrow(InsufficientStockException::class);
});

test('dispose() reduces the container remaining quantity and writes a DISPOSE row', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '20.000000', ledgerCtx($user));
    $row = $service->dispose($container->id, '5.000000', ledgerCtx($user));

    expect($row->txn_type)->toBe('DISPOSE');
    expect($row->qty_out_base)->toBe('5.000000');
    expect($row->balance_base)->toBe('15.000000');
    expect($container->fresh()->remaining_qty_base)->toBe('15.000000');
});

test('dispose() sets the container to DISPOSED once fully consumed', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '10.000000', ledgerCtx($user));
    $service->dispose($container->id, '10.000000', ledgerCtx($user));

    $fresh = $container->fresh();
    expect($fresh->remaining_qty_base)->toBe('0.000000');
    expect($fresh->status)->toBe('DISPOSED');
});

test('dispose() rejects a quantity larger than what remains in the container', function () {
    $service = app(LedgerService::class);
    $user = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '5.000000', ledgerCtx($user));

    expect(fn () => $service->dispose($container->id, '10.000000', ledgerCtx($user)))
        ->toThrow(InsufficientStockException::class);
});

test('a full receive -> issue -> return -> adjust -> dispose sequence produces an intact hash chain', function () {
    $service = app(LedgerService::class);
    $creator = User::factory()->create();
    $approver = User::factory()->create();
    $container = makeContainer();

    $service->receive($container->id, '20.000000', ledgerCtx($creator));
    $service->issue($container->id, '5.000000', ledgerCtx($creator));
    $service->return($container->id, '2.000000', ledgerCtx($creator));
    $service->adjust($container->id, '-1.000000', ledgerCtx($creator, [
        'remark' => 'พบส่วนต่างจากการตรวจนับประจำเดือน',
        'approvedBy' => $approver->id,
    ]));
    $service->dispose($container->id, '3.000000', ledgerCtx($creator));

    $result = app(LedgerHasher::class)->verifyChain($container->item_id);

    expect($result['ok'])->toBeTrue();
    expect($container->fresh()->remaining_qty_base)->toBe('13.000000');
});
