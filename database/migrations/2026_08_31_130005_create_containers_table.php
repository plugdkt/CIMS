<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('barcode', 64)->unique();
            $table->foreignId('item_id')->constrained('items');
            $table->unsignedInteger('location_id')->nullable();
            $table->string('lot_no', 64)->nullable();
            $table->date('received_at');
            $table->date('expiry_date')->nullable();
            $table->date('opened_at')->nullable();
            $table->decimal('initial_qty_base', 18, 6);
            $table->decimal('remaining_qty_base', 18, 6);
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->enum('status', ['SEALED', 'IN_USE', 'EMPTY', 'DISPOSED', 'QUARANTINE'])
                ->default('SEALED');
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->index(['item_id', 'status'], 'idx_cont_item_status');
            $table->index('expiry_date', 'idx_cont_expiry');
            $table->index('location_id', 'idx_cont_location');
        });

        DB::statement(
            'ALTER TABLE containers ADD CONSTRAINT chk_cont_remaining CHECK (remaining_qty_base >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('containers');
    }
};
