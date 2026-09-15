<?php

use App\Domain\Chemicals\DTO\PubChemCompoundData;
use App\Domain\Chemicals\Services\ChemicalSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('ChemicalSyncService::syncItem updates formula and GHS data on existing item', function () {
    $item = makeItem([
        'cas_no' => '64-17-5',
        'name_en' => 'Ethanol',
        'formula' => null,
        'ghs_codes' => null,
    ]);

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
                                'Value' => ['StringWithMarkup' => [
                                    ['String' => 'H225: Highly flammable liquid and vapor'],
                                ]],
                            ],
                            [
                                'Name' => 'Precautionary Statement Codes',
                                'Value' => ['StringWithMarkup' => [
                                    ['String' => 'P210, P233'],
                                ]],
                            ],
                        ],
                    ]],
                ]],
            ]]],
        ], 200),
    ]);

    $service = app(ChemicalSyncService::class);
    $result = $service->syncItem($item);

    expect($result)->toBeTrue();
    $fresh = $item->fresh();
    expect($fresh->formula)->toBe('C2H6O');
    expect($fresh->ghs_codes)->toBe(['GHS02']);
    expect($fresh->h_statements)->toBe(['H225']);
    expect($fresh->p_statements)->toBe(['P210', 'P233']);
    expect($fresh->specification)->toContain('PubChem CID: 702');

    $audit = \App\Models\AuditLog::where('action', 'PUBCHEM_SYNC')->where('entity_id', $item->id)->first();
    expect($audit)->not->toBeNull();
    expect($audit->old_value['formula'])->toBeNull();
    expect($audit->new_value['formula'])->toBe('C2H6O');
    expect($audit->new_value['ghs_codes'])->toBe(['GHS02']);
});

test('ChemicalSyncService::createFromPubChem creates a new item with generated item_code and null base_unit_id', function () {
    $compound = new PubChemCompoundData(
        cid: 702,
        title: 'Ethanol',
        molecularFormula: 'C2H6O',
        molecularWeight: '46.07',
        iupacName: 'ethanol',
        signalWord: 'Danger',
        ghsCodes: ['GHS02'],
        hStatements: ['H225'],
        pStatements: ['P210'],
    );

    $service = app(ChemicalSyncService::class);
    $item = $service->createFromPubChem($compound, '64-17-5');

    expect($item->id)->toBeGreaterThan(0);
    expect($item->item_code)->toMatch('/^CHM-\d{4}-\d{5}$/');
    expect($item->name_th)->toBe('Ethanol');
    expect($item->name_en)->toBe('Ethanol');
    expect($item->cas_no)->toBe('64-17-5');
    expect($item->formula)->toBe('C2H6O');
    expect($item->ghs_codes)->toBe(['GHS02']);
    expect($item->h_statements)->toBe(['H225']);
    expect($item->p_statements)->toBe(['P210']);
    expect($item->base_unit_id)->toBeNull();
    expect($item->is_active)->toBeTrue();
});

test('ChemicalSyncService::createFromPubChem returns existing item when CAS matches to prevent TOCTOU duplicate', function () {
    $compound = new PubChemCompoundData(
        cid: 702,
        title: 'Ethanol',
        molecularFormula: 'C2H6O',
        molecularWeight: '46.07',
        iupacName: 'ethanol',
        signalWord: 'Danger',
        ghsCodes: ['GHS02'],
        hStatements: ['H225'],
        pStatements: ['P210'],
    );

    $service = app(ChemicalSyncService::class);
    $item1 = $service->createFromPubChem($compound, '64-17-5');
    $item2 = $service->createFromPubChem($compound, '64-17-5');

    expect($item2->id)->toBe($item1->id);
    expect(\App\Models\Item::where('cas_no', '64-17-5')->count())->toBe(1);
});

test('ChemicalSyncService::generateNextItemCode increments sequentially and avoids collisions', function () {
    $service = app(ChemicalSyncService::class);
    $code1 = $service->generateNextItemCode();

    makeItem(['item_code' => $code1]);

    $code2 = $service->generateNextItemCode();

    expect($code1)->toMatch('/^CHM-\d{4}-\d{5}$/');
    expect($code2)->toMatch('/^CHM-\d{4}-\d{5}$/');
    expect($code2)->not->toBe($code1);
});

test('ChemicalSyncService::syncItem resolves chemical with percentage and commercial notes in name', function () {
    $item = makeItem([
        'cas_no' => null,
        'name_th' => 'Ethanol 95% COM องค์การสุรา',
        'name_en' => null,
        'formula' => null,
        'ghs_codes' => null,
    ]);

    Http::fake([
        '*rest/pug/compound/name/Ethanol/*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 702,
                'Title' => 'Ethanol',
                'MolecularFormula' => 'C2H6O',
                'MolecularWeight' => '46.07',
            ]]],
        ], 200),
        '*rest/pug_view/data/compound/702/*' => Http::response([
            'Record' => ['Section' => []],
        ], 200),
    ]);

    $service = app(ChemicalSyncService::class);
    $result = $service->syncItem($item);

    expect($result)->toBeTrue();
    expect($item->fresh()->formula)->toBe('C2H6O');
    expect($item->fresh()->name_en)->toBe('Ethanol');
});
