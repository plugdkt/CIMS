<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-050 / SEC-PD-02, SEC-PD-04: spec's own §5.2 DDL for `users` has no PDPA-specific
 * columns at all — same class of gap as every other "the rule needs enforcing now"
 * addition in this project (T-016/T-022's `ulid`, T-035's `overage_approved_by`,
 * T-043's `stock_ledger.approved_by`).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SEC-PD-02: when + which version of the Privacy Notice this user accepted.
            $table->dateTime('privacy_consent_at')->nullable()->after('is_active');
            $table->string('privacy_consent_version', 16)->nullable()->after('privacy_consent_at');
            // SEC-PD-04: when this user's PII was permanently scrubbed post-retention
            // (ledger/audit rows referencing their user_id stay intact — only this row's
            // own PII columns are overwritten; see PseudonymizeUserService).
            $table->dateTime('pseudonymized_at')->nullable()->after('privacy_consent_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_consent_at', 'privacy_consent_version', 'pseudonymized_at']);
        });
    }
};
