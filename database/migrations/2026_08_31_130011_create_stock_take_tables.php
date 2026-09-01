<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('doc_no', 32)->unique();
            $table->unsignedInteger('lab_id');
            $table->date('count_date');
            $table->enum('status', ['OPEN', 'COUNTING', 'PENDING_APPROVAL', 'APPROVED', 'CANCELLED'])
                ->default('OPEN');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
        });

        Schema::create('stock_take_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_id')->constrained('stock_takes');
            $table->foreignId('container_id')->constrained('containers');
            $table->decimal('system_qty_base', 18, 6);
            $table->decimal('counted_qty_base', 18, 6)->nullable();
            $table->decimal('diff_base', 18, 6)->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users');
            $table->dateTime('counted_at')->nullable();

            $table->unique(['stock_take_id', 'container_id'], 'uq_take_container');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');
    }
};
