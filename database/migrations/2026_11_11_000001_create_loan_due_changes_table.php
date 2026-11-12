<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 3 (hand-out / return / renew): the history of due-date changes (`loan_due_changes`, spec
 * section 5 / BR-L07) and the two foreign keys on `loan_movements` that step 1 left for "when the loan tables exist".
 * No data access. `cmis_app` has no rights on a brand-new table until docker/mariadb/restrict_app_grants.sql is
 * re-run, so deploy is (1) php artisan migrate (2) re-run the grants script on every database. `loan_due_changes`
 * is an ordinary table (full DML): a PENDING row is updated when it is decided.
 *
 * Difference from the spec DDL (additive): `decision_note`, what the approver wrote when deciding a renewal
 * (`reason` is the borrower's own reason; a rejection needs somewhere to say why).
 *
 * The ALTER on `loan_movements` only adds constraints (DDL): the append-only triggers and grants concern
 * UPDATE / DELETE of rows and are untouched, and no existing row is rewritten (rows of steps 1-2 carry NULL in both
 * columns). ON DELETE RESTRICT (the default), so a loan that has movements can never be deleted.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loan_due_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->date('old_due_date');
            $table->date('new_due_date');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('reason', 500)->nullable();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED']);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('acted_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->dateTime('created_at');

            $table->unique(['loan_id', 'seq'], 'uq_ldc');
            $table->index(['status', 'loan_id'], 'idx_ldc_status');
        });

        DB::statement('ALTER TABLE loan_due_changes ADD CONSTRAINT chk_ldc_dates CHECK (new_due_date > old_due_date)');

        Schema::table('loan_movements', function (Blueprint $table) {
            $table->foreign('loan_id', 'fk_lm_loan')->references('id')->on('loans');
            $table->foreign('loan_line_id', 'fk_lm_loan_line')->references('id')->on('loan_lines');
        });
    }

    public function down(): void
    {
        Schema::table('loan_movements', function (Blueprint $table) {
            $table->dropForeign('fk_lm_loan_line');
            $table->dropForeign('fk_lm_loan');
        });

        Schema::dropIfExists('loan_due_changes');
    }
};
