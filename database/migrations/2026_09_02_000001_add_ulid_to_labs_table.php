<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs.
 * Spec's §5.2 DDL for `labs` has no `ulid` column — T-022 finally adds the small
 * admin Labs CRUD the app needs (goods_receipts.lab_id is NOT NULL, and the table
 * was empty with no seeder — see CLAUDE.md), so it needs a ULID-addressed edit
 * route like every other user-facing resource. User-approved 2026-09-02. Table is
 * still empty at this point, so no backfill step is needed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('labs', function (Blueprint $table) {
            $table->ulid('ulid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('labs', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });
    }
};
