<?php

use App\Domain\Chemicals\Services\PubChemClient;
use Illuminate\Support\Facades\Http;

test('lookupByCas returns a compound with parsed GHS data', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [[
                'CID' => 5234,
                'Title' => 'Sodium Chloride',
                'MolecularFormula' => 'ClNa',
                'MolecularWeight' => '58.44',
                'IUPACName' => 'sodium;chloride',
            ]]],
        ], 200),
        '*rest/pug_view/data/compound/5234/*' => Http::response([
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
                                    'String' => ' ',
                                    'Markup' => [['URL' => 'https://pubchem.ncbi.nlm.nih.gov/images/ghs/GHS05.svg']],
                                ]]],
                            ],
                            [
                                'Name' => 'Signal',
                                'Value' => ['StringWithMarkup' => [['String' => 'Danger']]],
                            ],
                            [
                                'Name' => 'GHS Hazard Statements',
                                'Value' => ['StringWithMarkup' => [
                                    ['String' => 'H318: Causes serious eye damage'],
                                    ['String' => 'H999: Not a real code we know about'],
                                ]],
                            ],
                            [
                                'Name' => 'Precautionary Statement Codes',
                                'Value' => ['StringWithMarkup' => [[
                                    'String' => 'P264+P265, P280, and P501',
                                ]]],
                            ],
                        ],
                    ]],
                ]],
            ]]],
        ], 200),
    ]);

    $client = app(PubChemClient::class);
    $result = $client->lookupByCas('7647-14-5');

    expect($result)->not->toBeNull();
    expect($result->cid)->toBe(5234);
    expect($result->title)->toBe('Sodium Chloride');
    expect($result->molecularFormula)->toBe('ClNa');
    expect($result->signalWord)->toBe('Danger');
    expect($result->ghsCodes)->toBe(['GHS05']);
    expect($result->hStatements)->toBe(['H318']); // H999 dropped — not in our config/ghs.php
    // P265 dropped too — config/ghs.php's T-015 reference table doesn't have every
    // official P-code either (same "not necessarily exhaustive" gap noted there).
    expect($result->pStatements)->toEqualCanonicalizing(['P264', 'P280', 'P501']);
});

test('lookupByCas returns null when PubChem has no match', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'Fault' => ['Code' => 'PUGREST.NotFound', 'Message' => 'No CIDs found'],
        ], 404),
    ]);

    expect(app(PubChemClient::class)->lookupByCas('0000-00-0'))->toBeNull();
});

test('lookupByCas returns null (not an exception) when PubChem is unreachable', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
    ]);

    expect(app(PubChemClient::class)->lookupByCas('7647-14-5'))->toBeNull();
});

test('lookupByCas caches the result so a second call makes no new HTTP request', function () {
    Http::fake([
        '*rest/pug/compound/xref/RegistryID/*' => Http::response([
            'PropertyTable' => ['Properties' => [['CID' => 5234, 'Title' => 'Sodium Chloride']]],
        ], 200),
        '*rest/pug_view/*' => Http::response(['Record' => ['Section' => []]], 200),
    ]);

    $client = app(PubChemClient::class);
    $client->lookupByCas('7647-14-5');
    $client->lookupByCas('7647-14-5');

    Http::assertSentCount(2); // one property call + one GHS call — not 4
});
