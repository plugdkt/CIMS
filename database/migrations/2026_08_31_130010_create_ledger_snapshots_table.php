<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ledger_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->char('period_ym', 7);
            $table->decimal('opening_base', 18, 6);
            $table->decimal('total_in_base', 18, 6);
            $table->decimal('total_out_base', 18, 6);
            $table->decimal('closing_base', 18, 6);
            $table->unsignedBigInteger('last_ledger_id');
            $table->dateTime('generated_at');

            $table->unique(['item_id', 'period_ym'], 'uq_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_snapshots');
    }
};
