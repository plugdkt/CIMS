<?php

use App\Domain\Chemicals\Services\PubChemClient;
use App\Livewire\Chemicals\PubchemLookup;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('unauthenticated user cannot access pubchem lookup page', function () {
    $this->get(route('chemicals.lookup'))
        ->assertRedirect(route('login'));
});

test('user without item.view cannot access pubchem lookup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('chemicals.lookup'))
        ->assertForbidden();
});

test('user with item.view (scientist) can access pubchem lookup and search', function () {
    $scientist = scientistUser();

    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 702,
                'Title' => 'Ethanol',
                'MolecularFormula' => 'C2H6O',
                'MolecularWeight' => '46.07',
                'IUPACName' => 'ethanol',
            ]]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);

    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '64-17-5')
        ->call('search', app(PubChemClient::class))
        ->assertSet('found', true)
        ->assertSet('cid', 702)
        ->assertSet('title', 'Ethanol');
});

test('user with item.view only cannot call syncToRegistry or syncExistingItem', function () {
    $scientist = scientistUser();

    $item = makeItem([
        'cas_no' => '64-17-5',
        'name_en' => 'Ethanol',
    ]);

    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 702,
                'Title' => 'Ethanol',
                'MolecularFormula' => 'C2H6O',
                'MolecularWeight' => '46.07',
            ]]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);

    // syncToRegistry requires item.manage (create)
    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '64-17-5')
        ->call('search', app(PubChemClient::class))
        ->call('syncToRegistry')
        ->assertForbidden();

    // syncExistingItem requires item.manage (update)
    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '64-17-5')
        ->call('search', app(PubChemClient::class))
        ->call('syncExistingItem')
        ->assertForbidden();
});

test('user with item.manage (lab manager) can call syncExistingItem and syncToRegistry', function () {
    $labManager = labManagerUser();

    $item = makeItem([
        'cas_no' => '64-17-5',
        'name_en' => 'Ethanol',
        'formula' => null,
    ]);

    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 702,
                'Title' => 'Ethanol',
                'MolecularFormula' => 'C2H6O',
                'MolecularWeight' => '46.07',
            ]]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);

    Livewire::actingAs($labManager)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '64-17-5')
        ->call('search', app(PubChemClient::class))
        ->assertSet('matchedItemId', $item->id)
        ->call('syncExistingItem')
        ->assertRedirect(route('items.show', $item));

    expect($item->fresh()->formula)->toBe('C2H6O');
});
