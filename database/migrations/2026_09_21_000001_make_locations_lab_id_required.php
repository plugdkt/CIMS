<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-requested 2026-09-21: each branch (lab) builds and sees its own independent
 * storage tree ("ผังจัดเก็บ") — no location is ever shared/unscoped between branches
 * anymore, so `lab_id` is no longer optional. Confirmed no cleanup needed: the
 * `locations` table had exactly one row at the time of this change, already carrying
 * a `lab_id`.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedInteger('lab_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedInteger('lab_id')->nullable()->change();
        });
    }
};
