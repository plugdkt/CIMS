<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-04: issuing more than 10% over `qty_requested` needs a LAB_MANAGER's approval — spec's
 * own DDL has no column to record who gave it (unlike BR-06's adjustment approval, which
 * has nowhere to live yet either since that's a later phase). Added here, on the line the
 * overage happened on, the same way T-016/T-022 added missing `ulid` columns mid-task when
 * spec's DDL had a gap AGENT RULE #9 needed filled. See CLAUDE.md.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->foreignId('overage_approved_by')->nullable()->after('remark')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overage_approved_by');
        });
    }
};
