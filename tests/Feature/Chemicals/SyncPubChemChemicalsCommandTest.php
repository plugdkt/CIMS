<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('chemicals:sync-pubchem outputs no items message when nothing matches criteria', function () {
    $this->artisan('chemicals:sync-pubchem')
        ->expectsOutput(__('chemicals.cmd_no_items'))
        ->assertSuccessful();
});

test('chemicals:sync-pubchem batch syncs items with CAS number and updates GHS and formula', function () {
    $item1 = makeItem([
        'cas_no' => '64-17-5',
        'name_en' => 'Ethanol',
        'formula' => null,
        'ghs_codes' => null,
    ]);

    $item2 = makeItem([
        'cas_no' => '7647-14-5',
        'name_en' => 'Sodium Chloride',
        'formula' => null,
        'ghs_codes' => null,
    ]);

    Http::fake([
        '*rest/pug/compound/xref/RegistryID/64-17-5*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 702,
                'Title' => 'Ethanol',
                'MolecularFormula' => 'C2H6O',
                'MolecularWeight' => '46.07',
            ]]],
        ], 200),
        '*rest/pug_view/data/compound/702/*' => Http::response([
            'Record' => ['Section' => [[
                'TOCHeading' => 'Safety and Hazards',
                'Section' => [[
                    'TOCHeading' => 'Hazards Identification',
                    'Section' => [[
                        'TOCHeading' => 'GHS Classification',
                        'Information' => [
                            [
                                'Name' => 'Pictogram(s)',
                                'Value' => ['StringWithMarkup' => [[
                                    'Markup' => [['URL' => 'https://pubchem.ncbi.nlm.nih.gov/images/ghs/GHS02.svg']],
                                ]]],
                            ],
                            [
                                'Name' => 'GHS Hazard Statements',
                                'Value' => ['StringWithMarkup' => [['String' => 'H225: Highly flammable liquid and vapor']]],
                            ],
                            [
                                'Name' => 'Precautionary Statement Codes',
                                'Value' => ['StringWithMarkup' => [['String' => 'P210']]],
                            ],
                        ],
                    ]],
                ]],
            ]]],
        ], 200),
        '*rest/pug/compound/xref/RegistryID/7647-14-5*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 5234,
                'Title' => 'Sodium Chloride',
                'MolecularFormula' => 'ClNa',
                'MolecularWeight' => '58.44',
            ]]],
        ], 200),
        '*rest/pug_view/data/compound/5234/*' => Http::response([
            'Record' => ['Section' => []],
        ], 200),
    ]);

    $this->artisan('chemicals:sync-pubchem', ['--delay' => 0])
        ->assertSuccessful();

    $fresh1 = $item1->fresh();
    expect($fresh1->formula)->toBe('C2H6O');
    expect($fresh1->ghs_codes)->toBe(['GHS02']);
    expect($fresh1->h_statements)->toBe(['H225']);

    $fresh2 = $item2->fresh();
    expect($fresh2->formula)->toBe('ClNa');
    expect($fresh2->ghs_codes)->toBe([]);

    // Check AuditLog
    expect(AuditLog::where('action', 'PUBCHEM_SYNC')->where('entity_id', $item1->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'PUBCHEM_SYNC')->where('entity_id', $item2->id)->exists())->toBeTrue();
});

test('chemicals:sync-pubchem skips items that already have GHS codes unless --force is specified', function () {
    $item = makeItem([
        'cas_no' => '64-17-5',
        'formula' => 'OLD_FORMULA',
        'ghs_codes' => ['GHS07'],
    ]);

    Http::fake();

    // Without --force, it should find 0 items to sync
    $this->artisan('chemicals:sync-pubchem')
        ->expectsOutput(__('chemicals.cmd_no_items'))
        ->assertSuccessful();

    Http::assertNothingSent();
});
