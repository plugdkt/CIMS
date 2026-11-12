<?php

use App\Models\LoanIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 4 (WP-4.6): the migration 2026_11_11_000002 itself — what up() leaves in the schema and what
 * down() does. down() must REFUSE, before touching anything, while it would destroy or orphan data (an incident row:
 * `loan_movements.incident_id` is append-only and cannot be cleared; an attachment of type LOAN_DOC: a narrower ENUM
 * would blank it), and work on an empty schema.
 *
 * The "works when empty" test really runs down() and up() again on `cmis_testing`. That is DDL: it ends the test's
 * transaction (MariaDB commits implicitly), so that test creates NO rows of its own (the seeders' rows are idempotent
 * and are committed by the concurrency tests anyway), and it always puts the schema back, also when an assertion fails.
 * It is skipped when the tables are not empty (rows left by the process-spawning concurrency tests of an earlier run
 * in a process that did not start from `migrate:fresh`).
 */

beforeEach(function () {
    Mail::fake();
});

function incidentMigration(): object
{
    return require database_path('migrations/2026_11_11_000002_create_loan_incidents_table.php');
}

/** @return array<string, mixed> what the migration is responsible for, read from the live schema */
function incidentSchemaState(): array
{
    $db = DB::getDatabaseName();
    $enum = DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'attachments' AND COLUMN_NAME = 'doc_type'", [$db]);

    return [
        'table' => Schema::hasTable('loan_incidents'),
        'trigger' => DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', $db)->where('TRIGGER_NAME', 'trg_loan_incidents_final')->exists(),
        'foreign_key' => DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $db)->where('TABLE_NAME', 'loan_movements')
            ->where('CONSTRAINT_NAME', 'fk_lm_incident')->where('CONSTRAINT_TYPE', 'FOREIGN KEY')->exists(),
        'checks' => DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', $db)->where('TABLE_NAME', 'loan_incidents')
            ->where('CONSTRAINT_TYPE', 'CHECK')->orderBy('CONSTRAINT_NAME')->pluck('CONSTRAINT_NAME')->all(),
        'doc_type' => (string) $enum->t,
    ];
}

const INCIDENT_SCHEMA_UP = [
    'table' => true,
    'trigger' => true,
    'foreign_key' => true,
    'checks' => ['chk_li_outcome', 'chk_li_state'],
    'doc_type' => "enum('SDS','INVOICE','PHOTO','OTHER','LOAN_DOC')",
];

test('up(): the incidents table with its CHECKs and its "final" trigger, the foreign key from loan_movements, and LOAN_DOC added at the END of attachments.doc_type', function () {
    expect(incidentSchemaState())->toBe(INCIDENT_SCHEMA_UP);

    // The existing ENUM values kept their positions (a value's stored number is its position): nothing was renumbered.
    $positions = DB::selectOne("SELECT FIND_IN_SET('SDS', 'SDS,INVOICE,PHOTO,OTHER,LOAN_DOC') AS sds");
    expect((int) $positions->sds)->toBe(1);
    expect(Schema::hasColumns('loan_incidents', [
        'ulid', 'loan_id', 'loan_line_id', 'kind', 'qty', 'description', 'resolution', 'disposition', 'value_snapshot',
        'external_ref', 'recorded_by', 'resolved_by', 'resolved_at', 'resolution_note', 'created_at', 'updated_at',
    ]))->toBeTrue();
    // Nothing is backfilled.
    expect(LoanIncident::count())->toBe(0);
});

test('down() refuses while an incident exists, and changes nothing', function () {
    [$lab, $asset] = loanBranch();
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), $asset, 2);
    $incident = recordLoanIncident($loan, $recorder, 'LOST', 1);

    expect(fn () => incidentMigration()->down())->toThrow(RuntimeException::class, 'Refusing to roll back: loan_incidents has rows');

    expect(incidentSchemaState())->toBe(INCIDENT_SCHEMA_UP);
    expect(LoanIncident::find($incident->id)?->resolution)->toBe('PENDING');
    expect(DB::table('loan_movements')->where('incident_id', $incident->id)->count())->toBe(1);
});

test('down() refuses while an attachment of type LOAN_DOC exists (even with no incident row), and changes nothing', function () {
    $uploader = scientistUser(['lab_id' => makeLab()->id]);
    DB::table('attachments')->insert([
        'ulid' => (string) Illuminate\Support\Str::ulid(), 'owner_type' => 'LoanIncident', 'owner_id' => 987654, 'doc_type' => 'LOAN_DOC',
        'original_name' => 'memo.pdf', 'stored_name' => 'memo-stored.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
        'sha256' => str_repeat('b', 64), 'version' => 1, 'uploaded_by' => $uploader->id, 'created_at' => now(),
    ]);
    expect(LoanIncident::count())->toBe(0);

    expect(fn () => incidentMigration()->down())->toThrow(RuntimeException::class, 'Refusing to roll back: attachments of type LOAN_DOC exist');

    expect(incidentSchemaState())->toBe(INCIDENT_SCHEMA_UP);
    expect(DB::table('attachments')->where('doc_type', 'LOAN_DOC')->count())->toBe(1);
});

test('down() works on an empty schema and up() restores it exactly (really run on cmis_testing; DDL, see the note at the top)', function () {
    expect(DB::getDatabaseName())->toBe('cmis_testing');
    if (DB::table('loan_incidents')->exists() || DB::table('attachments')->where('doc_type', 'LOAN_DOC')->exists()) {
        $this->markTestSkipped('loan_incidents / LOAN_DOC attachments are not empty in cmis_testing (left by the concurrency tests); run after a fresh migration.');
    }
    $migration = incidentMigration();
    $otherTypes = DB::table('attachments')->selectRaw('doc_type, COUNT(*) AS n')->groupBy('doc_type')->pluck('n', 'doc_type')->all();

    try {
        $migration->down();

        expect(incidentSchemaState())->toBe([
            'table' => false,
            'trigger' => false,
            'foreign_key' => false,
            'checks' => [],
            'doc_type' => "enum('SDS','INVOICE','PHOTO','OTHER')",
        ]);
        // The column step 1 created stays (only its foreign key goes), and the ledger's own triggers are untouched.
        expect(Schema::hasColumn('loan_movements', 'incident_id'))->toBeTrue();
        expect(DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'like', 'trg_loan_movements_%')->count())->toBe(2);
    } finally {
        if (! Schema::hasTable('loan_incidents')) {
            $migration->up();
        }
    }

    expect(incidentSchemaState())->toBe(INCIDENT_SCHEMA_UP);
    expect(DB::table('attachments')->selectRaw('doc_type, COUNT(*) AS n')->groupBy('doc_type')->pluck('n', 'doc_type')->all())->toBe($otherTypes);
});

test('after the round trip the application can still write an incident (grants and constraints are in place)', function () {
    [$lab, $asset] = loanBranch();
    $recorder = scientistUser(['lab_id' => $lab->id]);
    $loan = handedOutLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    $incident = recordLoanIncident($loan, $recorder, 'DAMAGED', 1);

    expect($incident->resolution)->toBe('PENDING');
    expect(DB::table('loan_movements')->where('incident_id', $incident->id)->value('movement_type'))->toBe('RETURN_DAMAGED');
    attachLoanIncidentFile($incident, $recorder, 'LOAN_DOC');
    expect(DB::table('attachments')->where('owner_type', 'LoanIncident')->where('owner_id', $incident->id)->value('doc_type'))->toBe('LOAN_DOC');
});
