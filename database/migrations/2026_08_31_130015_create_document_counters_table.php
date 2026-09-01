<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs BR-09 (document numbering). Not in spec §5.2's DDL block, but BR-09 explicitly
 * requires "SELECT ... FOR UPDATE บนตาราง counter" — this is that table.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 16);
            $table->char('fiscal_year', 4);
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['prefix', 'fiscal_year'], 'uq_counter_prefix_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
