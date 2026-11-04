<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 2: the loan document (`loans`), its lines (`loan_lines`) and the approval trail
 * (`loan_approvals`). Creates empty tables only (no data access). `cmis_app` has no rights on brand-new
 * tables until docker/mariadb/restrict_app_grants.sql is re-run, so deploy is (1) php artisan migrate
 * (2) re-run the grants script on every database. None of these three is append-only (like requisitions).
 *
 * Differences from the spec DDL (spec section 5), all additive:
 *  - `loans.submitted_at`: when the request left DRAFT (queue order, and "waiting since" on the pages).
 *  - `loan_lines.qty_approved`: NULL until a manager approves; may be lower than `qty` (the requested quantity),
 *    so what was asked for is never overwritten (FR-LN-13). Later steps hand out `qty_approved`.
 * `loan_movements.loan_id/loan_line_id` get their foreign keys in step 3 (an ALTER on that append-only table
 * is deliberately not done here).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('doc_no', 32)->unique();
            $table->unsignedInteger('lab_id');
            $table->foreignId('borrower_id')->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->enum('borrower_status', ['LECTURER', 'STAFF', 'STUDENT']);
            $table->enum('purpose_type', ['TEACHING', 'RESEARCH', 'OTHER']);
            $table->string('purpose_detail', 500)->nullable();
            $table->date('loan_date');
            $table->date('due_date');
            $table->date('original_due_date');
            $table->unsignedTinyInteger('renew_count')->default(0);
            $table->foreignId('advisor_id')->nullable()->constrained('users');
            $table->dateTime('advisor_signed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('handed_out_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->enum('status', [
                'DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED', 'ON_LOAN',
                'PARTIALLY_RETURNED', 'CLOSED', 'REJECTED', 'CANCELLED',
            ])->default('DRAFT');
            $table->string('remark', 500)->nullable();
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->index(['lab_id', 'status'], 'idx_loan_lab_status');
            $table->index(['borrower_id', 'status'], 'idx_loan_borrower');
            $table->index(['status', 'due_date'], 'idx_loan_due');
            $table->index(['advisor_id', 'status'], 'idx_loan_advisor');
        });

        DB::statement('ALTER TABLE loans ADD CONSTRAINT chk_loan_dates CHECK (due_date >= loan_date)');

        Schema::create('loan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('loan_asset_id')->constrained('loan_assets');
            $table->decimal('qty', 18, 6);
            $table->decimal('qty_approved', 18, 6)->nullable();
            $table->decimal('qty_returned_ok', 18, 6)->default(0);
            $table->decimal('qty_returned_damaged', 18, 6)->default(0);
            $table->decimal('qty_lost', 18, 6)->default(0);
            $table->enum('condition_out', ['GOOD', 'FAIR', 'DAMAGED'])->nullable();
            $table->string('condition_out_note', 255)->nullable();
            $table->string('remark', 255)->nullable();

            $table->unique(['loan_id', 'line_no'], 'uq_loan_line');
            $table->unique(['loan_id', 'loan_asset_id'], 'uq_loan_line_asset');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loan_lines ADD CONSTRAINT chk_ll CHECK (
                qty > 0
                AND (qty_approved IS NULL OR (qty_approved > 0 AND qty_approved <= qty))
                AND qty_returned_ok + qty_returned_damaged + qty_lost <= COALESCE(qty_approved, qty)
            )
        SQL);

        Schema::create('loan_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->enum('step', ['ADVISOR', 'MANAGER']);
            $table->foreignId('actor_id')->constrained('users');
            $table->enum('decision', ['APPROVE', 'REJECT']);
            $table->string('reason', 500)->nullable();
            $table->dateTime('acted_at');
            $table->binary('ip_address', 16)->nullable();

            $table->index(['loan_id', 'step'], 'idx_lapp_loan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_approvals');
        Schema::dropIfExists('loan_lines');
        Schema::dropIfExists('loans');
    }
};
