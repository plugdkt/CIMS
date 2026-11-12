<?php

use App\Domain\Loan\Services\LoanAssetImportService;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Lab;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 WP-5.4 (FR-LN-05): `loans:import-assets <file> --lab=CODE --by=USERNAME [--dry-run]`, run here through
 * the real command on cmis_testing only.
 *
 * The rules as the code has them (LoanAssetImportService):
 *  - the whole file is checked first; ANY problem = nothing is written (and the problems are listed by line);
 *  - a clean file is written in ONE transaction, every row an ordinary REGISTER (hash chain, LOAN_ASSET_REGISTER),
 *    plus one LOAN_ASSET_IMPORT row (branch, row count, sha256 — no file name, no content);
 *  - --by must be active and hold `loan.asset_manage` of that branch, or be ADMIN; --lab must be an active branch;
 *  - at most 2,000 data rows; running the same file again is refused as a whole (tags / pools already exist).
 */

function importCsvPath(): string
{
    $dir = storage_path('framework/testing/loan-import');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir.'/'.bin2hex(random_bytes(6)).'.csv';
}

/** Writes a CSV file (first row = heading) and returns its path. */
function importCsv(array $rows, string $prefix = ''): string
{
    $path = importCsvPath();
    $handle = fopen($path, 'wb');
    fwrite($handle, $prefix);
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    fclose($handle);

    return $path;
}

afterEach(function () {
    foreach (glob(storage_path('framework/testing/loan-import/*.csv')) ?: [] as $file) {
        @unlink($file);
    }
});

/** @return array{Lab, User, Item, Item} a branch, its warehouse manager, and two lendable items */
function importScene(): array
{
    $lab = makeLab();

    return [$lab, auditorUser(['lab_id' => $lab->id]), makeCountItem(), makeCountItem()];
}

/** Everything an import may write, for "nothing was written" checks. */
function importState(): array
{
    return [
        'assets' => LoanAsset::count(),
        'movements' => LoanMovement::count(),
        'register_audit' => AuditLog::where('action', 'LOAN_ASSET_REGISTER')->count(),
        'import_audit' => AuditLog::where('action', 'LOAN_ASSET_IMPORT')->count(),
    ];
}

const IMPORT_HEADER = ['item_code', 'tracking_mode', 'qty', 'asset_tag', 'location_code', 'max_loan_days', 'replacement_value', 'condition_note'];

function runImport($test, string $path, Lab $lab, User $by, bool $dryRun = false)
{
    return $test->artisan('loans:import-assets', array_filter([
        'path' => $path, '--lab' => $lab->code, '--by' => $by->username, '--dry-run' => $dryRun,
    ]));
}

test('a dry run of a clean file says how many rows are ready and writes nothing', function () {
    [$lab, $manager, $i1, $i2] = importScene();
    $path = importCsv([IMPORT_HEADER, [$i1->item_code, 'UNIT', '', 'MS-0001', '', '', '', ''], [$i2->item_code, 'POOL', '5', '', '', '', '', '']]);
    $before = importState();

    runImport($this, $path, $lab, $manager, true)
        ->expectsOutputToContain(__('loans.import.dry_run_ok', ['count' => 2, 'lab' => $lab->code]))
        ->assertExitCode(0);

    expect(importState())->toBe($before);
});

test('a clean file registers every row through the ledger (REGISTER + hash chain + audit), records ONE import row, and loans:verify passes', function () {
    [$lab, $manager, $i1, $i2] = importScene();
    $location = makeLocationForLab($lab);
    $path = importCsv([
        IMPORT_HEADER,
        [strtolower($i1->item_code), 'unit', '1', 'MS-0001', $location->code, '14', '15000.50', 'มีรอยขีดข่วนเล็กน้อย'],
        [$i1->item_code, 'UNIT', '', 'MS-0002', '', '', '', ''],
        [$i2->item_code, 'POOL', '25', '', '', '7', '120', ''],
        ['', '', '', '', '', '', '', ''],                      // a blank line is skipped
    ], "\xEF\xBB\xBF");                                          // a BOM is fine

    runImport($this, $path, $lab, $manager)->expectsOutputToContain(__('loans.import.done', ['count' => 3, 'lab' => $lab->code]))->assertExitCode(0);

    $assets = LoanAsset::where('lab_id', $lab->id)->orderBy('id')->get();
    expect($assets)->toHaveCount(3);
    expect($assets->map(fn ($a) => [$a->item_id, $a->tracking_mode, $a->asset_tag, $a->qty_total, $a->qty_on_loan, $a->status])->all())->toBe([
        [$i1->id, 'UNIT', 'MS-0001', '1.000000', '0.000000', 'ACTIVE'],
        [$i1->id, 'UNIT', 'MS-0002', '1.000000', '0.000000', 'ACTIVE'],
        [$i2->id, 'POOL', null, '25.000000', '0.000000', 'ACTIVE'],
    ]);
    expect([$assets[0]->location_id, $assets[0]->max_loan_days, (string) $assets[0]->replacement_value, $assets[0]->condition_note])
        ->toBe([$location->id, 14, '15000.50', 'มีรอยขีดข่วนเล็กน้อย']);

    foreach ($assets as $asset) {
        expect(LoanMovement::where('loan_asset_id', $asset->id)->pluck('movement_type')->all())->toBe(['REGISTER']);
        expect(LoanMovement::where('loan_asset_id', $asset->id)->value('created_by'))->toBe($manager->id);
        assertLoanChainIntact($asset);
    }
    expect(AuditLog::where('action', 'LOAN_ASSET_REGISTER')->where('user_id', $manager->id)->count())->toBe(3);

    $import = AuditLog::where('action', 'LOAN_ASSET_IMPORT')->sole();
    expect([$import->user_id, $import->entity_type, $import->entity_id])->toBe([$manager->id, Lab::class, $lab->id]);
    expect($import->new_value)->toBe(['lab_id' => $lab->id, 'rows' => 3, 'sha256' => hash_file('sha256', $path)]);
    expect(json_encode($import->new_value))->not->toContain(basename($path))->not->toContain('MS-0001');

    $this->artisan('loans:verify')->assertExitCode(0);
});

test('running the same file again is refused as a whole: every row is reported and nothing more is written', function () {
    [$lab, $manager, $i1, $i2] = importScene();
    $path = importCsv([IMPORT_HEADER, [$i1->item_code, 'UNIT', '', 'MS-0001', '', '', '', ''], [$i2->item_code, 'POOL', '5', '', '', '', '', '']]);
    runImport($this, $path, $lab, $manager)->assertExitCode(0);
    $before = importState();

    runImport($this, $path, $lab, $manager, true)->assertExitCode(1);
    runImport($this, $path, $lab, $manager)
        ->expectsOutputToContain(__('loans.import.tag_exists'))
        ->expectsOutputToContain(__('loans.import.pool_exists'))
        ->expectsOutputToContain(__('loans.import.nothing_written'))
        ->assertExitCode(1);

    expect(importState())->toBe($before);
});

test('a UNIT row WITHOUT asset_tag is refused (the import never generates a tag), so a file can never register the same pieces twice; the web form still generates one', function () {
    [$lab, $manager, $i1, $i2] = importScene();
    $path = importCsv([
        IMPORT_HEADER,
        [$i2->item_code, 'POOL', '3', '', '', '', '', ''],
        [$i1->item_code, 'UNIT', '', '', '', '', '', ''],
    ]);
    $before = importState();

    // Dry run and real run both refuse the whole file, naming the line and the column.
    runImport($this, $path, $lab, $manager, true)->expectsOutputToContain(__('loans.import.tag_required'))->assertExitCode(1);
    runImport($this, $path, $lab, $manager)
        ->expectsOutputToContain(__('loans.import.tag_required'))
        ->expectsOutputToContain(__('loans.import.nothing_written'))
        ->assertExitCode(1);
    runImport($this, $path, $lab, $manager)->assertExitCode(1);     // run again: still nothing
    expect(importState())->toBe($before);

    // The rule is the import's own: registering through the ledger (the web form) without a tag still gets one.
    $asset = registerLoanAsset($manager, $lab, 'UNIT', 1, $i1);
    expect($asset->asset_tag)->toStartWith('LA-');
});

test('a missing, unknown or doubled column refuses the whole file before any row is read', function (array $header, string $problem) {
    [$lab, $manager, $i1] = importScene();
    $row = array_fill(0, count($header), '');
    $path = importCsv([$header, $row]);
    $before = importState();

    runImport($this, $path, $lab, $manager, true)->expectsOutputToContain(__('loans.import.'.$problem))->assertExitCode(1);
    runImport($this, $path, $lab, $manager)->expectsOutputToContain(__('loans.import.nothing_written'))->assertExitCode(1);
    expect(importState())->toBe($before);
})->with([
    'qty missing' => [['item_code', 'tracking_mode'], 'column_missing'],
    'item_code missing' => [['tracking_mode', 'qty'], 'column_missing'],
    'unknown column' => [['item_code', 'tracking_mode', 'qty', 'price'], 'column_unknown'],
    'doubled column' => [['item_code', 'tracking_mode', 'qty', 'qty'], 'column_twice'],
]);

test('an empty file, a heading only, or a missing file is refused', function () {
    [$lab, $manager] = importScene();
    $before = importState();

    $empty = importCsvPath();
    file_put_contents($empty, '');
    runImport($this, $empty, $lab, $manager, true)->expectsOutputToContain(__('loans.import.header_missing'))->assertExitCode(1);
    runImport($this, importCsv([IMPORT_HEADER]), $lab, $manager, true)->expectsOutputToContain(__('loans.import.no_rows'))->assertExitCode(1);
    runImport($this, importCsvPath(), $lab, $manager, true)->expectsOutputToContain(__('loans.import.file_unreadable'))->assertExitCode(1);

    expect(importState())->toBe($before);
});

test('one bad row among good ones refuses the WHOLE file (all or nothing), naming the problem', function (array $bad, string $column, string $problem, array $params = []) {
    [$lab, $manager, $i1, $i2] = importScene();
    $notCountable = makeItem();    // base unit g: cannot be lent
    $otherLocation = makeLocationForLab(makeLab());
    $bad = array_map(fn ($v) => match ($v) {
        '{ITEM}' => $i1->item_code, '{ITEM2}' => $i2->item_code, '{G_ITEM}' => $notCountable->item_code,
        '{FOREIGN_LOC}' => $otherLocation->code, default => $v,
    }, $bad);
    $path = importCsv([
        IMPORT_HEADER,
        [$i1->item_code, 'UNIT', '', 'GOOD-0001', '', '', '', ''],
        $bad,
    ]);
    $before = importState();

    $out = runImport($this, $path, $lab, $manager);
    $out->expectsOutputToContain(__('loans.import.'.$problem, $params))
        ->expectsOutputToContain(__('loans.import.nothing_written'))
        ->assertExitCode(1);

    expect(importState())->toBe($before);
    expect(LoanAsset::where('asset_tag', 'GOOD-0001')->exists())->toBeFalse();
})->with([
    'item not found' => [['NO-SUCH-ITEM', 'POOL', '3', '', '', '', '', ''], 'item_code', 'item_not_found'],
    'item not counted in pieces' => [['{G_ITEM}', 'POOL', '3', '', '', '', '', ''], 'item_code', 'item_not_countable'],
    'bad mode' => [['{ITEM2}', 'BOX', '3', '', '', '', '', ''], 'tracking_mode', 'mode_invalid'],
    'pool qty 0' => [['{ITEM2}', 'POOL', '0', '', '', '', '', ''], 'qty', 'qty_whole'],
    'pool qty decimal' => [['{ITEM2}', 'POOL', '1.5', '', '', '', '', ''], 'qty', 'qty_whole'],
    'pool qty negative' => [['{ITEM2}', 'POOL', '-3', '', '', '', '', ''], 'qty', 'qty_whole'],
    'pool qty text' => [['{ITEM2}', 'POOL', 'ten', '', '', '', '', ''], 'qty', 'qty_whole'],
    'unit qty 2' => [['{ITEM2}', 'UNIT', '2', '', '', '', '', ''], 'qty', 'unit_qty_one'],
    'tag on a pool' => [['{ITEM2}', 'POOL', '3', 'TAG-1', '', '', '', ''], 'asset_tag', 'tag_unit_only'],
    'tag too long' => [['{ITEM2}', 'UNIT', '', str_repeat('T', 65), '', '', '', ''], 'asset_tag', 'too_long', ['max' => 64]],
    'tag twice in file (any case)' => [['{ITEM}', 'UNIT', '', 'good-0001', '', '', '', ''], 'asset_tag', 'tag_twice_in_file', ['other' => 2]],
    'location of another branch' => [['{ITEM2}', 'POOL', '3', '', '{FOREIGN_LOC}', '', '', ''], 'location_code', 'location_not_in_lab'],
    'location unknown' => [['{ITEM2}', 'POOL', '3', '', 'NO-SUCH-LOC', '', '', ''], 'location_code', 'location_not_in_lab'],
    'max days 0' => [['{ITEM2}', 'POOL', '3', '', '', '0', '', ''], 'max_loan_days', 'days_invalid'],
    'max days 366' => [['{ITEM2}', 'POOL', '3', '', '', '366', '', ''], 'max_loan_days', 'days_invalid'],
    'max days text' => [['{ITEM2}', 'POOL', '3', '', '', 'abc', '', ''], 'max_loan_days', 'days_invalid'],
    'value negative' => [['{ITEM2}', 'POOL', '3', '', '', '', '-1', ''], 'replacement_value', 'value_invalid'],
    'value 3 decimals' => [['{ITEM2}', 'POOL', '3', '', '', '', '1.234', ''], 'replacement_value', 'value_invalid'],
    'value with comma' => [['{ITEM2}', 'POOL', '3', '', '', '', '1,000', ''], 'replacement_value', 'value_invalid'],
    'value formula' => [['{ITEM2}', 'POOL', '3', '', '', '', '=1+1', ''], 'replacement_value', 'value_invalid'],
    'note too long' => [['{ITEM2}', 'POOL', '3', '', '', '', '', str_repeat('ก', 256)], 'condition_note', 'too_long', ['max' => 255]],
]);

test('a row with the wrong number of cells, and the same POOL twice in one file, are refused', function () {
    [$lab, $manager, $i1, $i2] = importScene();

    $short = importCsvPath();
    file_put_contents($short, implode(',', IMPORT_HEADER)."\n{$i1->item_code},POOL,3\n");
    runImport($this, $short, $lab, $manager, true)->expectsOutputToContain(__('loans.import.cell_count'))->assertExitCode(1);

    $twice = importCsv([IMPORT_HEADER, [$i2->item_code, 'POOL', '3', '', '', '', '', ''], [$i2->item_code, 'POOL', '4', '', '', '', '', '']]);
    runImport($this, $twice, $lab, $manager)->expectsOutputToContain(__('loans.import.pool_twice_in_file', ['other' => 2]))->assertExitCode(1);

    expect(importState())->toBe(['assets' => 0, 'movements' => 0, 'register_audit' => 0, 'import_audit' => 0]);

    // An existing ACTIVE pool of the same item in the branch.
    registerLoanAsset($manager, $lab, 'POOL', 2, $i1);
    $before = importState();
    runImport($this, importCsv([IMPORT_HEADER, [$i1->item_code, 'POOL', '3', '', '', '', '', '']]), $lab, $manager)
        ->expectsOutputToContain(__('loans.import.pool_exists'))->assertExitCode(1);
    expect(importState())->toBe($before);
    // A tag already in the register (of ANY branch).
    $foreign = registerLoanAsset(auditorUser(['lab_id' => makeLab()->id]), null, 'UNIT');
    $before = importState();
    runImport($this, importCsv([IMPORT_HEADER, [$i2->item_code, 'UNIT', '', $foreign->asset_tag, '', '', '', '']]), $lab, $manager)
        ->expectsOutputToContain(__('loans.import.tag_exists'))->assertExitCode(1);
    expect(importState())->toBe($before);
});

test('at most 2,000 data rows: 2,000 pass the dry run, 2,001 are refused', function () {
    [$lab, $manager, $i1] = importScene();
    $rows = [IMPORT_HEADER];
    for ($n = 1; $n <= 2000; $n++) {
        $rows[] = [$i1->item_code, 'UNIT', '', sprintf('BULK-%05d', $n), '', '', '', ''];
    }

    runImport($this, importCsv($rows), $lab, $manager, true)
        ->expectsOutputToContain(__('loans.import.dry_run_ok', ['count' => 2000, 'lab' => $lab->code]))->assertExitCode(0);

    $rows[] = [$i1->item_code, 'UNIT', '', 'BULK-02001', '', '', '', ''];
    $before = importState();
    runImport($this, importCsv($rows), $lab, $manager)
        ->expectsOutputToContain(__('loans.import.too_many_rows', ['max' => LoanAssetImportService::MAX_ROWS]))
        ->assertExitCode(1);
    expect(importState())->toBe($before);
    expect(LoanAssetImportService::MAX_ROWS)->toBe(2000);
});

test('who may import: the branch\'s warehouse managers and ADMIN; not a manager of another branch, a scientist, an inactive or unknown user; the branch must exist and be active', function () {
    [$lab, $manager, $i1] = importScene();
    $path = importCsv([IMPORT_HEADER, [$i1->item_code, 'UNIT', '', 'WHO-0001', '', '', '', '']]);
    $before = importState();

    $refused = [
        'manager of another branch' => auditorUser(['lab_id' => makeLab()->id]),
        'manager with no branch' => auditorUser(['lab_id' => null]),
        'scientist of the branch' => scientistUser(['lab_id' => $lab->id]),
        'staff of the branch' => staffUser(['lab_id' => $lab->id]),
        'inactive manager of the branch' => auditorUser(['lab_id' => $lab->id, 'is_active' => false]),
        'inactive ADMIN' => adminUser(['is_active' => false]),
    ];
    foreach ($refused as $name => $user) {
        runImport($this, $path, $lab, $user, true)->expectsOutputToContain(__('loans.import.actor_not_allowed'))->assertExitCode(1);
        runImport($this, $path, $lab, $user)->expectsOutputToContain(__('loans.import.actor_not_allowed'))->assertExitCode(1);
        expect(importState())->toBe($before, $name);
    }

    $this->artisan('loans:import-assets', ['path' => $path, '--lab' => $lab->code, '--by' => 'nobody-here'])
        ->expectsOutputToContain(__('loans.import.actor_not_found'))->assertExitCode(1);
    $this->artisan('loans:import-assets', ['path' => $path, '--lab' => 'NO-SUCH-LAB', '--by' => $manager->username])
        ->expectsOutputToContain(__('loans.import.lab_not_found'))->assertExitCode(1);
    $closed = makeLab(['is_active' => false]);
    $this->artisan('loans:import-assets', ['path' => $path, '--lab' => $closed->code, '--by' => $manager->username])
        ->expectsOutputToContain(__('loans.import.lab_not_found'))->assertExitCode(1);
    expect(importState())->toBe($before);

    // ADMIN may import into any branch (no branch of their own needed); the LAB_MANAGER of the branch may too.
    runImport($this, $path, $lab, adminUser(['lab_id' => null]))->assertExitCode(0);
    $path2 = importCsv([IMPORT_HEADER, [$i1->item_code, 'UNIT', '', 'WHO-0002', '', '', '', '']]);
    runImport($this, $path2, $lab, labManagerUser(['lab_id' => $lab->id]))->assertExitCode(0);
    expect(LoanAsset::where('lab_id', $lab->id)->pluck('asset_tag')->sort()->values()->all())->toBe(['WHO-0001', 'WHO-0002']);
});

test('SEC-IN-10: formula text in an imported tag / note is stored as plain text, shown escaped, and neutralised in every report export', function () {
    [$lab, $manager, $i1] = importScene();
    $path = importCsv([
        IMPORT_HEADER,
        [$i1->item_code, 'UNIT', '', '=HYPERLINK("http://evil.example","x")', '', '', '', '@SUM(1+1)'],
        [$i1->item_code, 'UNIT', '', '+cmd|calc', '', '', '', '-2+3'],
        [$i1->item_code, 'UNIT', '', '<script>alert(1)</script>', '', '', '', ''],
    ]);

    runImport($this, $path, $lab, $manager)->assertExitCode(0);
    expect(LoanAsset::where('asset_tag', '=HYPERLINK("http://evil.example","x")')->value('condition_note'))->toBe('@SUM(1+1)');

    $rows = loanReportCsvForImport($this, $manager, 'availability');
    $tags = collect(array_slice($rows, 1))->pluck(3)->sort()->values()->all();
    expect($tags)->toBe(collect(["'=HYPERLINK(\"http://evil.example\",\"x\")", "'+cmd|calc", '<script>alert(1)</script>'])->sort()->values()->all());

    // The register page escapes it.
    $this->actingAs($manager)->get(route('loans.assets.index'))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

/** The availability CSV of the loan reports, parsed (LoanReportTest has the full helper; this file must run alone). */
function loanReportCsvForImport($test, User $user, string $report): array
{
    $body = $test->actingAs($user)->get(route('loans.reports.export', ['report' => $report]))->assertOk()->getContent();
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, substr($body, 3));
    rewind($handle);
    $rows = [];
    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = $cells;
    }
    fclose($handle);

    return $rows;
}
