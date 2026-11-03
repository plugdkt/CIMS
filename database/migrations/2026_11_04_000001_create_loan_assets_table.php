<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 1: the register of lending assets ("ทะเบียนของให้ยืม"), one row per UNIT item or per
 * POOL of identical items, owned by one branch. Creates an empty table only (no data access): `cmis_app`
 * has no rights on a brand-new table until docker/mariadb/restrict_app_grants.sql is re-run, so deploy is
 * (1) php artisan migrate (2) re-run the grants script on every database.
 *
 * Quantities are DECIMAL(18,6) like everywhere else but the module only accepts whole numbers.
 * `pool_key` is a stored generated column so MariaDB (no partial unique index) can still refuse a second
 * ACTIVE POOL for the same (branch, item): it is NULL for UNIT rows and for RETIRED rows, and NULLs never
 * collide in a unique index, so a retired pool does not block registering a new one for the same pair.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loan_assets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->unsignedInteger('lab_id');
            $table->foreignId('item_id')->constrained('items');
            $table->enum('tracking_mode', ['UNIT', 'POOL']);
            $table->string('asset_tag', 64)->nullable()->unique();
            $table->unsignedInteger('location_id')->nullable();
            $table->decimal('qty_total', 18, 6);
            $table->decimal('qty_on_loan', 18, 6)->default(0);
            $table->decimal('qty_unavailable', 18, 6)->default(0);
            $table->unsignedSmallInteger('max_loan_days')->nullable();
            $table->decimal('replacement_value', 14, 2)->nullable();
            $table->string('condition_note', 255)->nullable();
            $table->enum('status', ['ACTIVE', 'RETIRED'])->default('ACTIVE');
            $table->string('pool_key', 40)->nullable()
                ->storedAs("CASE WHEN tracking_mode = 'POOL' AND status = 'ACTIVE' THEN CONCAT(lab_id, '-', item_id) END")
                ->unique('uq_la_pool');
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->index(['lab_id', 'item_id', 'status'], 'idx_la_lab_item');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE loan_assets ADD CONSTRAINT chk_la_qty CHECK (
                qty_total >= 0 AND qty_on_loan >= 0 AND qty_unavailable >= 0
                AND qty_on_loan + qty_unavailable <= qty_total
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_assets');
    }
};
