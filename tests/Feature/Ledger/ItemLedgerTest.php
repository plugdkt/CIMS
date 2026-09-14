<?php

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Livewire\Items\ItemLedger;
use App\Models\Container;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a user without ledger.view gets 403 on the item ledger page', function () {
    $user = User::factory()->create();
    $item = makeItem();

    $this->actingAs($user)->get(route('items.ledger', $item))->assertStatus(403);
});

test('SCIENTIST can view the ledger page and sees the F-03 header', function () {
    $scientist = scientistUser();
    $item = makeItem(['brand' => 'AR Grade Co.', 'grade' => 'AR']);

    $this->actingAs($scientist)
        ->get(route('items.ledger', $item))
        ->assertOk()
        ->assertSee($item->name_th)
        ->assertSee('AR Grade Co.');
});

test('the ledger lists RECEIVE and ISSUE rows with the correct in/out/balance columns', function () {
    $scientist = scientistUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = User::factory()->create();
    $ledgerService = app(LedgerService::class);

    $container = Container::create([
        'item_id' => $item->id,
        'barcode' => 'BC-LEDGER-1',
        'received_at' => now()->toDateString(),
        'initial_qty_base' => '0.000000',
        'remaining_qty_base' => '0.000000',
        'status' => 'SEALED',
    ]);
    $ctx = new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id, receiverName: 'สมชาย ใจดี');
    $ledgerService->receive($container->id, '100.000000', $ctx);
    $ledgerService->issue($container->id, '30.000000', $ctx);

    Livewire::actingAs($scientist)
        ->test(ItemLedger::class, ['item' => $item])
        ->assertSee('100.000000')
        ->assertSee('30.000000')
        ->assertSee('70.000000')
        ->assertSee('สมชาย ใจดี');
});

test('the unit switcher converts displayed quantities without touching stored data', function () {
    $scientist = scientistUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $kg = Unit::where('code', 'kg')->firstOrFail();
    $creator = User::factory()->create();
    $ledgerService = app(LedgerService::class);

    $container = Container::create([
        'item_id' => $item->id,
        'barcode' => 'BC-LEDGER-2',
        'received_at' => now()->toDateString(),
        'initial_qty_base' => '0.000000',
        'remaining_qty_base' => '0.000000',
        'status' => 'SEALED',
    ]);
    $ledgerService->receive($container->id, '2500.000000', new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id));

    Livewire::actingAs($scientist)
        ->test(ItemLedger::class, ['item' => $item])
        ->set('displayUnitId', $kg->id)
        ->assertSee('2.500000')
        ->assertDontSee('2500.000000');

    expect(\App\Models\StockLedger::where('item_id', $item->id)->first()->qty_in_base)->toBe('2500.000000');
});

test('filtering by transaction type only shows matching rows', function () {
    $scientist = scientistUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = User::factory()->create();
    $ledgerService = app(LedgerService::class);

    $container = Container::create([
        'item_id' => $item->id,
        'barcode' => 'BC-LEDGER-3',
        'received_at' => now()->toDateString(),
        'initial_qty_base' => '0.000000',
        'remaining_qty_base' => '0.000000',
        'status' => 'SEALED',
    ]);
    $ctx = new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id);
    $ledgerService->receive($container->id, '50.000000', $ctx);
    $ledgerService->issue($container->id, '10.000000', $ctx);

    Livewire::actingAs($scientist)
        ->test(ItemLedger::class, ['item' => $item])
        ->set('txnType', 'ISSUE')
        ->assertSee('10.000000')
        ->assertDontSee('50.000000');
});

test('branch scoping: a LAB_MANAGER only sees ledger rows for containers located in their own branch', function () {
    $lab = makeLab();
    $otherLab = makeLab();
    $ownLocation = makeLocationForLab($lab);
    $otherLocation = makeLocationForLab($otherLab);
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = User::factory()->create();
    $ledgerService = app(LedgerService::class);

    $ownContainer = Container::create([
        'item_id' => $item->id, 'barcode' => 'BC-OWN-LAB', 'location_id' => $ownLocation->id,
        'received_at' => now()->toDateString(), 'initial_qty_base' => '0', 'remaining_qty_base' => '0', 'status' => 'SEALED',
    ]);
    $otherContainer = Container::create([
        'item_id' => $item->id, 'barcode' => 'BC-OTHER-LAB', 'location_id' => $otherLocation->id,
        'received_at' => now()->toDateString(), 'initial_qty_base' => '0', 'remaining_qty_base' => '0', 'status' => 'SEALED',
    ]);
    $unlocatedContainer = Container::create([
        'item_id' => $item->id, 'barcode' => 'BC-NO-LOCATION',
        'received_at' => now()->toDateString(), 'initial_qty_base' => '0', 'remaining_qty_base' => '0', 'status' => 'SEALED',
    ]);
    $ctx = new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id);
    $ledgerService->receive($ownContainer->id, '11.000000', $ctx);
    $ledgerService->receive($otherContainer->id, '22.000000', $ctx);
    $ledgerService->receive($unlocatedContainer->id, '33.000000', $ctx);

    $manager = labManagerUser(['lab_id' => $lab->id]);

    Livewire::actingAs($manager)
        ->test(ItemLedger::class, ['item' => $item])
        ->assertSee('11.000000')
        ->assertDontSee('22.000000')
        ->assertDontSee('33.000000');

    Livewire::actingAs(scientistUser())
        ->test(ItemLedger::class, ['item' => $item])
        ->assertSee('11.000000')
        ->assertSee('22.000000')
        ->assertSee('33.000000');
});

test('filtering by container barcode only shows that container\'s rows', function () {
    $scientist = scientistUser();
    $item = makeItem(['base_unit_id' => Unit::where('code', 'g')->value('id')]);
    $g = Unit::where('code', 'g')->firstOrFail();
    $creator = User::factory()->create();
    $ledgerService = app(LedgerService::class);

    $containerA = Container::create([
        'item_id' => $item->id, 'barcode' => 'BC-AAA', 'received_at' => now()->toDateString(),
        'initial_qty_base' => '0', 'remaining_qty_base' => '0', 'status' => 'SEALED',
    ]);
    $containerB = Container::create([
        'item_id' => $item->id, 'barcode' => 'BC-BBB', 'received_at' => now()->toDateString(),
        'initial_qty_base' => '0', 'remaining_qty_base' => '0', 'status' => 'SEALED',
    ]);
    $ctx = new LedgerEntryData(displayUnitId: $g->id, createdBy: $creator->id);
    $ledgerService->receive($containerA->id, '11.000000', $ctx);
    $ledgerService->receive($containerB->id, '22.000000', $ctx);

    Livewire::actingAs($scientist)
        ->test(ItemLedger::class, ['item' => $item])
        ->set('containerBarcode', 'AAA')
        ->assertSee('11.000000')
        ->assertDontSee('22.000000');
});
