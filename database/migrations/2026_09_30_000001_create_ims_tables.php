<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMS = the branch's central-store stock (what was actually purchased and received from the
 * university's own warehouse system), one layer above "working stock" (containers).
 * `labs.id` is an unsigned INT and `units.id` an unsigned SMALLINT, so neither can use
 * `foreignId()` (see CLAUDE.md's note on units FKs).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ims_receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedInteger('lab_id');
            $table->string('doc_no', 64)->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('purchase_round', 32)->nullable();
            $table->string('source_file_path')->nullable();
            $table->string('source_file_name')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->index(['lab_id', 'status']);
        });

        Schema::create('ims_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ims_receipt_id')->constrained('ims_receipts')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->nullable()->constrained('items');
            $table->string('item_code_raw', 64)->nullable();
            $table->string('name_raw', 500)->nullable();
            $table->string('lot_no', 64)->nullable();
            $table->decimal('qty', 18, 6);
            $table->unsignedSmallInteger('unit_id')->nullable();
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('remark', 255)->nullable();

            $table->foreign('unit_id')->references('id')->on('units');
        });

        Schema::create('ims_lots', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->unsignedInteger('lab_id');
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('ims_receipt_id')->nullable()->constrained('ims_receipts');
            $table->string('doc_no', 64)->nullable();
            $table->string('lot_no', 64)->nullable();
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('purchase_round', 32)->nullable();
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('qty_received_base', 18, 6);
            $table->decimal('qty_remaining_base', 18, 6);
            $table->date('received_at');
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->index(['lab_id', 'item_id']);
        });

        // Append-only by convention (same rule as stock_ledger): a lot's balance only
        // ever changes together with one new row here.
        Schema::create('ims_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ims_lot_id')->constrained('ims_lots');
            $table->string('type', 16);
            $table->decimal('qty_base', 18, 6);
            $table->decimal('balance_after_base', 18, 6);
            $table->unsignedSmallInteger('display_unit_id');
            $table->string('ref_doc_no', 64)->nullable();
            $table->string('remark', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('display_unit_id')->references('id')->on('units');
        });

        Schema::table('containers', function (Blueprint $table) {
            $table->foreignId('ims_lot_id')->nullable()->after('item_id')->constrained('ims_lots');
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ims_lot_id');
        });
        Schema::dropIfExists('ims_movements');
        Schema::dropIfExists('ims_lots');
        Schema::dropIfExists('ims_receipt_lines');
        Schema::dropIfExists('ims_receipts');
    }
};
