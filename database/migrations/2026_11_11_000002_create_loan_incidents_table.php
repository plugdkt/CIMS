<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 4 (damage / loss): `loan_incidents` (spec section 5, BR-L11), the foreign key step 1 left open
 * on `loan_movements.incident_id`, and one more value, `LOAN_DOC`, in the ENUM `attachments.doc_type`.
 *
 * `loan_incidents` is an ordinary table (full DML for `cmis_app`: a PENDING row is updated once, when its result is
 * recorded), so docker/mariadb/restrict_app_grants.sql needs no edit, only a re-run after migrating. A result is
 * final (spec 8.3): the trigger below refuses any UPDATE of a row that is no longer PENDING, on top of the service
 * and the model.
 *
 * Difference from the spec DDL (additive): `loan_id` (the document, so "no PENDING incident" of BR-L12 and the branch
 * checks need no join through the line), and the CHECKs.
 *
 * !! `attachments` is an EXISTING table with real rows. The ALTER only ADDS an ENUM value at the end of the list
 * (no existing row changes; the values 'SDS','INVOICE','PHOTO','OTHER' keep their positions), but it is still DDL on
 * live data: BACK UP THE DATABASE BEFORE DEPLOYING THIS MIGRATION.
 *
 * down() is refused while there is anything it would destroy or orphan (an incident row, or an attachment of type
 * LOAN_DOC, which a narrower ENUM would silently blank): it throws before changing anything.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loan_incidents', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('loan_id')->constrained('loans');
            $table->foreignId('loan_line_id')->constrained('loan_lines');
            $table->enum('kind', ['DAMAGED', 'LOST']);
            $table->decimal('qty', 18, 6);
            $table->string('description', 500);
            $table->enum('resolution', ['PENDING', 'REPAIRED', 'REPLACED', 'COMPENSATED', 'WAIVED'])->default('PENDING');
            $table->enum('disposition', ['RETURN_TO_SERVICE', 'WRITE_OFF'])->nullable();
            // Reference value on the day the result was recorded. Never shown to the borrower.
            $table->decimal('value_snapshot', 14, 2)->nullable();
            $table->string('external_ref', 255)->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['loan_id', 'resolution'], 'idx_li_loan');
            $table->index(['resolution', 'id'], 'idx_li_resolution');
            $table->index('loan_line_id', 'idx_li_line');
        });

        // The same rules LoanIncidentService enforces, as a last line of defence in the database (BR-L11).
        DB::statement(<<<'SQL'
            ALTER TABLE loan_incidents ADD CONSTRAINT chk_li_state CHECK (
                qty > 0
                AND (
                    (resolution = 'PENDING' AND resolved_by IS NULL AND resolved_at IS NULL AND disposition IS NULL)
                    OR (resolution <> 'PENDING' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL
                        AND disposition IS NOT NULL AND resolved_by <> recorded_by)
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loan_incidents ADD CONSTRAINT chk_li_outcome CHECK (
                (kind <> 'LOST' OR (resolution <> 'REPAIRED' AND (disposition IS NULL OR disposition = 'WRITE_OFF')))
                AND (resolution <> 'REPAIRED' OR disposition = 'RETURN_TO_SERVICE')
                AND (resolution <> 'REPLACED' OR disposition = 'WRITE_OFF')
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loan_incidents_final BEFORE UPDATE ON loan_incidents
            FOR EACH ROW BEGIN
              IF OLD.resolution <> 'PENDING' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a resolved loan incident is final';
              END IF;
            END
        SQL);

        // DDL only: the append-only triggers / grants of loan_movements concern UPDATE / DELETE of rows; no row is
        // rewritten (every existing row carries NULL in incident_id). RESTRICT, like the other two.
        Schema::table('loan_movements', function (Blueprint $table) {
            $table->foreign('incident_id', 'fk_lm_incident')->references('id')->on('loan_incidents');
        });

        // Adds LOAN_DOC at the END of the list (see the warning in the header). Static SQL, no input.
        DB::statement("ALTER TABLE attachments MODIFY doc_type ENUM('SDS','INVOICE','PHOTO','OTHER','LOAN_DOC') NOT NULL");
    }

    public function down(): void
    {
        // Refuse BEFORE touching anything: rolling back would orphan loan_movements.incident_id (append-only, it
        // cannot be cleared) and blank the doc_type of every LOAN_DOC attachment.
        if (Schema::hasTable('loan_incidents') && DB::table('loan_incidents')->exists()) {
            throw new RuntimeException('Refusing to roll back: loan_incidents has rows (loan_movements reference them). Restore from backup instead.');
        }
        if (DB::table('attachments')->where('doc_type', 'LOAN_DOC')->exists()) {
            throw new RuntimeException('Refusing to roll back: attachments of type LOAN_DOC exist; narrowing the ENUM would blank them.');
        }

        DB::statement("ALTER TABLE attachments MODIFY doc_type ENUM('SDS','INVOICE','PHOTO','OTHER') NOT NULL");

        Schema::table('loan_movements', function (Blueprint $table) {
            $table->dropForeign('fk_lm_incident');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS trg_loan_incidents_final');
        Schema::dropIfExists('loan_incidents');
    }
};
