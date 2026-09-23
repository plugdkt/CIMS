<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-requested 2026-09-23: a warehouse manager approving a requisition needs to be able
 * to approve *less* than what was requested (e.g. requested 100, approves 50 as the
 * appropriate amount) — spec's own DDL has no column for this, the same class of gap as
 * `overage_approved_by` above. Nullable, so an existing already-decided row (approved
 * before this feature shipped) reads as "approved = requested" via a `?? qty_requested_base`
 * fallback at every read site, rather than needing a backfill.
 *
 * Both a raw (`qty_approved`, in the line's own `unit_id`) and a base-unit column are added,
 * mirroring `qty_requested`/`qty_requested_base`'s own shape — the approver enters a number
 * in the same unit the line was requested in, and the base value is what every downstream
 * ceiling check (BR-04 tolerance, "is this line fully issued") actually compares against.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->decimal('qty_approved', 18, 6)->nullable()->after('qty_requested_base');
            $table->decimal('qty_approved_base', 18, 6)->nullable()->after('qty_approved');
        });
    }

    public function down(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn(['qty_approved', 'qty_approved_base']);
        });
    }
};
