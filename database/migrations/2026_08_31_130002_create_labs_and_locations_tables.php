<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('labs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 32)->unique();
            $table->string('name_th', 255);
            $table->string('faculty', 255)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedInteger('lab_id')->nullable();
            $table->string('code', 32)->unique();
            $table->string('name', 128);
            $table->enum('level_type', ['BUILDING', 'ROOM', 'CABINET', 'SHELF']);
            $table->string('storage_class', 64)->nullable();

            $table->foreign('parent_id', 'fk_loc_parent')->references('id')->on('locations');
            $table->foreign('lab_id')->references('id')->on('labs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('labs');
    }
};
