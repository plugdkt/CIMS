<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs.
 * Spec's §5.2 DDL for `locations` has no `ulid` column (unlike `items`/`attachments`) —
 * T-016 adds one so the location tree CRUD/detail routes can bind on it instead of
 * the auto-increment `id`, consistent with every other user-facing resource in the app.
 * User-approved 2026-09-01. Table is empty pre-T-016, so no backfill step is needed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->ulid('ulid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });
    }
};
