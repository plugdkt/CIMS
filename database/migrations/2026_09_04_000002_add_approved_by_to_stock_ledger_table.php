<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-06 literally requires stock_ledger rows to "have approved_by = LAB_MANAGER"
 * for ADJUST_IN/ADJUST_OUT, but spec's own §5.2 DDL for stock_ledger has no such
 * column (unlike stock_takes/disposals, which do). Same class of gap as T-035's
 * overage_approved_by — the rule needs enforcing now, so the column is added here
 * rather than waiting for a future schema revision. Append-only triggers (T-017)
 * are column-agnostic (BEFORE UPDATE/DELETE), so they apply to this column too
 * with no trigger changes needed.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn('approved_by');
        });
    }
};
