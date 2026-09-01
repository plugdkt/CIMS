<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('doc_no', 32)->unique();
            $table->date('receipt_date');
            $table->string('po_no', 64)->nullable();
            $table->string('invoice_no', 64)->nullable();
            $table->string('supplier', 255)->nullable();
            $table->unsignedInteger('lab_id');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'CANCELLED'])->default('DRAFT');
            $table->foreignId('received_by')->constrained('users');
            $table->dateTime('confirmed_at')->nullable();
            $table->string('remark', 500)->nullable();
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->constrained('items');
            $table->unsignedSmallInteger('container_count');
            $table->decimal('qty_per_container', 18, 6);
            $table->unsignedSmallInteger('unit_id');
            $table->decimal('qty_total_base', 18, 6);
            $table->string('lot_no', 64)->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedInteger('location_id')->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();

            $table->foreign('unit_id')->references('id')->on('units');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->unique(['goods_receipt_id', 'line_no'], 'uq_grn_line');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
