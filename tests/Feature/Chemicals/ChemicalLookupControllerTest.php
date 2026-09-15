<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function fakePubChemForSodiumChloride(): void
{
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 5234,
                'Title' => 'Sodium Chloride',
                'MolecularFormula' => 'ClNa',
                'MolecularWeight' => '58.44',
            ]]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);
}

test('a user without item.view gets 403 on the lookup endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('items.lookup-pubchem', ['query' => '7647-14-5', 'by' => 'cas']))
        ->assertStatus(403);
});

test('a SCIENTIST (item.view) gets a found result for a real-looking CAS', function () {
    fakePubChemForSodiumChloride();
    $scientist = scientistUser();

    $this->actingAs($scientist)
        ->getJson(route('items.lookup-pubchem', ['query' => '7647-14-5', 'by' => 'cas']))
        ->assertOk()
        ->assertJson([
            'found' => true,
            'title' => 'Sodium Chloride',
            'molecular_formula' => 'ClNa',
        ]);
});

test('an invalid "by" value is rejected by validation', function () {
    $scientist = scientistUser();

    $this->actingAs($scientist)
        ->getJson(route('items.lookup-pubchem', ['query' => 'x', 'by' => 'bogus']))
        ->assertStatus(422);
});
