<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a lot physically sits in the branch's central store (set after the receipt is confirmed). */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ims_lots', function (Blueprint $table) {
            $table->unsignedInteger('location_id')->nullable()->after('lab_id');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }

    public function down(): void
    {
        Schema::table('ims_lots', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
