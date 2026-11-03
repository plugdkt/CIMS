<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 1: the lending module's own append-only ledger (`loan_movements`), with its own hash
 * chain per asset (App\Domain\Loan\Services\LoanLedgerHasher). It is NOT stock_ledger and never touches it.
 *
 * Append-only in three layers, same as stock_ledger: (1) the DB user `cmis_app` gets only SELECT/INSERT
 * (docker/mariadb/restrict_app_grants.sql must list this table, then be re-run), (2) the triggers below,
 * (3) the model refuses update/delete. Empty table, no data access in the migration.
 *
 * `loan_id`, `loan_line_id`, `incident_id` are plain nullable columns now: their tables arrive in later
 * steps, which add the foreign keys then.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loan_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_asset_id')->constrained('loan_assets');
            $table->unsignedInteger('lab_id');
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->unsignedBigInteger('loan_line_id')->nullable();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->enum('movement_type', [
                'REGISTER', 'HANDOUT', 'RETURN_OK', 'RETURN_DAMAGED', 'MARK_LOST',
                'REPAIR_DONE', 'WRITE_OFF', 'REPLACE_IN', 'ADJUST_IN', 'ADJUST_OUT', 'RETIRE',
            ]);
            $table->decimal('qty', 18, 6);
            $table->enum('condition_recorded', ['GOOD', 'FAIR', 'DAMAGED'])->nullable();
            $table->decimal('qty_total_after', 18, 6);
            $table->decimal('qty_on_loan_after', 18, 6);
            $table->decimal('qty_unavailable_after', 18, 6);
            $table->string('note', 500)->nullable();
            // Free-text outside reference; not part of the hash. Required for WRITE_OFF and for ADJUST_OUT of an asset that was ever lent.
            $table->string('external_ref', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('created_at', 6);
            $table->char('prev_row_hash', 64)->nullable();
            $table->char('row_hash', 64);

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->index(['loan_asset_id', 'id'], 'idx_lm_asset');
            $table->index('loan_id', 'idx_lm_loan');
        });

        // approved_by is not part of the hash, so the database enforces it as well (loans:verify checks old rows).
        // RETIRE is included (FR-LN-03: retiring needs a different approver, like adjusting a count).
        DB::statement(<<<'SQL'
            ALTER TABLE loan_movements ADD CONSTRAINT chk_lm_approval CHECK (
                movement_type NOT IN ('ADJUST_IN', 'ADJUST_OUT', 'REPLACE_IN', 'RETIRE')
                OR (approved_by IS NOT NULL AND approved_by <> created_by)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE loan_movements ADD CONSTRAINT chk_lm_qty CHECK (
                qty > 0
                AND qty_total_after >= 0 AND qty_on_loan_after >= 0 AND qty_unavailable_after >= 0
                AND qty_on_loan_after + qty_unavailable_after <= qty_total_after
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loan_movements_no_update BEFORE UPDATE ON loan_movements
            FOR EACH ROW BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loan_movements is append-only';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_loan_movements_no_delete BEFORE DELETE ON loan_movements
            FOR EACH ROW BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'loan_movements is append-only';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loan_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_loan_movements_no_delete');
        Schema::dropIfExists('loan_movements');
    }
};
