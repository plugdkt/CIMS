<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 16)->unique();
            $table->string('name_th', 64);
            $table->enum('dimension', ['MASS', 'VOLUME', 'COUNT']);
            $table->decimal('factor_to_base', 24, 12);
            $table->boolean('is_base')->default(false);
            $table->smallInteger('sort_order')->default(0);
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 32)->unique();
            $table->string('name_th', 128);
            $table->boolean('is_chemical')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('units');
    }
};
