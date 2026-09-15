<?php

use App\Livewire\Chemicals\PubchemLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a user without item.view is forbidden from mounting the lookup page', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(PubchemLookup::class)->assertForbidden();
});

test('a SCIENTIST can search by CAS and see the result rendered', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [['CID' => 5234, 'Title' => 'Sodium Chloride', 'MolecularFormula' => 'ClNa']]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);
    $scientist = scientistUser();

    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '7647-14-5')
        ->call('search')
        ->assertSee('Sodium Chloride')
        ->assertSee('ClNa');
});

test('searching with an empty query is rejected by validation', function () {
    $scientist = scientistUser();

    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('query', '')
        ->call('search')
        ->assertHasErrors('query');
});

test('a not-found result shows the not-found message', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response(['Fault' => ['Code' => 'PUGREST.NotFound']], 404),
    ]);
    $scientist = scientistUser();

    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '0000-00-0')
        ->call('search')
        ->assertSee(__('chemicals.not_found'));
});

test('syncToRegistry creates a new item in registry', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [['CID' => 702, 'Title' => 'Ethanol', 'MolecularFormula' => 'C2H6O']]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);
    $manager = labManagerUser();

    Livewire::actingAs($manager)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '64-17-5')
        ->call('search')
        ->call('syncToRegistry')
        ->assertRedirect();

    $item = \App\Models\Item::where('cas_no', '64-17-5')->first();
    expect($item)->not->toBeNull();
    expect($item->name_en)->toBe('Ethanol');
    expect($item->formula)->toBe('C2H6O');
});

test('matchedItem detects existing registered chemical and displays status banner', function () {
    makeItem(['cas_no' => '64-17-5', 'item_code' => 'CHM-EXIST', 'name_th' => 'เอทานอลทดสอบ']);

    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [['CID' => 702, 'Title' => 'Ethanol', 'MolecularFormula' => 'C2H6O']]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);
    $scientist = scientistUser();

    Livewire::actingAs($scientist)
        ->test(PubchemLookup::class)
        ->set('by', 'cas')
        ->set('query', '64-17-5')
        ->call('search')
        ->assertSee('CHM-EXIST')
        ->assertSee('เอทานอลทดสอบ');
});
