<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('doc_no', 32)->unique();
            $table->unsignedInteger('lab_id');
            $table->date('doc_date');
            $table->foreignId('requester_id')->constrained('users');
            $table->enum('requester_status', ['LECTURER', 'STAFF', 'STUDENT']);
            $table->string('requester_phone', 32)->nullable();
            $table->string('student_code', 32)->nullable();
            $table->string('program', 255)->nullable();
            $table->string('faculty', 255)->nullable();
            $table->set('request_type', ['CHEMICAL', 'CONSUMABLE']);
            $table->enum('purpose_type', ['TEACHING', 'RESEARCH', 'OTHER']);
            $table->string('purpose_detail', 500)->nullable();
            $table->foreignId('advisor_id')->nullable()->constrained('users');
            $table->dateTime('advisor_signed_at')->nullable();
            $table->char('advisor_signature_hash', 64)->nullable();
            $table->foreignId('scientist_id')->nullable()->constrained('users');
            $table->enum('scientist_decision', ['APPROVE', 'REJECT'])->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->dateTime('scientist_signed_at')->nullable();
            $table->enum('status', [
                'DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED',
                'REJECTED', 'PARTIALLY_ISSUED', 'ISSUED', 'CANCELLED',
            ])->default('DRAFT');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->index('status', 'idx_req_status');
            $table->index(['requester_id', 'status'], 'idx_req_requester');
            $table->index(['advisor_id', 'status'], 'idx_req_advisor');
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items');
            $table->decimal('qty_requested', 18, 6);
            $table->unsignedSmallInteger('unit_id');
            $table->decimal('qty_requested_base', 18, 6);
            $table->decimal('qty_issued_base', 18, 6)->default(0);
            $table->decimal('qty_returned_base', 18, 6)->default(0);
            $table->string('reference_doc', 255)->nullable();
            $table->string('remark', 255)->nullable();

            $table->foreign('unit_id')->references('id')->on('units');
            $table->unique(['requisition_id', 'line_no'], 'uq_req_line');
        });

        DB::statement(
            'ALTER TABLE requisition_items ADD CONSTRAINT chk_req_qty CHECK (qty_requested > 0)'
        );

        Schema::create('requisition_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->enum('step', ['ADVISOR', 'SCIENTIST']);
            $table->foreignId('actor_id')->constrained('users');
            $table->enum('decision', ['APPROVE', 'REJECT']);
            $table->string('reason', 500)->nullable();
            $table->dateTime('acted_at');
            $table->binary('ip_address', 16)->nullable();

            $table->index(['requisition_id', 'step'], 'idx_appr_req');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_approvals');
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisitions');
    }
};
