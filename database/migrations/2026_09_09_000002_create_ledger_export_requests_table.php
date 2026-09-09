<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-02: F-03 ledger PDF exports beyond LedgerExportController::ASYNC_ROW_THRESHOLD
 * rows are generated in the background (GenerateLedgerPdfExportJob) instead of in the
 * request/response cycle — this table tracks each request so the requester can be
 * notified and the finished file downloaded later. Spec's own §5.2 DDL has no table
 * for this (NFR-02 wasn't wired for any report until now — see CLAUDE.md), same class
 * of "the rule needs enforcing now" gap as every other added-mid-project table/column.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ledger_export_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('requested_by')->constrained('users');
            // units.id is smallIncrements (smallint unsigned), not bigint — foreignId()
            // here would mismatch and fail with errno 150, same as every other unit_id
            // FK in the schema (see stock_ledger.display_unit_id for the same shape).
            $table->unsignedSmallInteger('display_unit_id');
            $table->foreign('display_unit_id')->references('id')->on('units');
            $table->json('filter');
            $table->enum('status', ['PENDING', 'READY', 'FAILED'])->default('PENDING');
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_export_requests');
    }
};
