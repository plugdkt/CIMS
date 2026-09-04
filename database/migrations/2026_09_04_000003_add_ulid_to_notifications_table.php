<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs. Spec's §5.2
 * DDL for `notifications` has no `ulid` column (unlike `items`/`attachments`) — T-044 adds
 * one so `/notifications/{notification}/read` can bind on it instead of the auto-increment
 * `id`, same gap already fixed once each for `locations` (T-016) and `labs` (T-022). Table
 * is empty pre-T-044 (nothing has written to it yet), so no backfill step is needed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->ulid('ulid')->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });
    }
};
