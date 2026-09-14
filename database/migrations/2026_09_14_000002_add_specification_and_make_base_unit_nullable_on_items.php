<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->text('specification')->nullable()->after('grade');
            $table->unsignedSmallInteger('base_unit_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('specification');
            $table->unsignedSmallInteger('base_unit_id')->nullable(false)->change();
        });
    }
};
