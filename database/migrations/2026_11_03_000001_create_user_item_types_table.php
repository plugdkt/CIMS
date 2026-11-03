<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-052 phase 3: which kinds of stock (chemical / consumable) each warehouse manager
 * (LAB_MANAGER / AUDITOR) is responsible for, on top of their branch (`users.lab_id`).
 *
 * Deny by default: a manager with no row here cannot see or decide requisitions of any kind.
 *
 * This migration ONLY creates the table (no data access): `cmis_app` has no rights on a brand-new table
 * until docker/mariadb/restrict_app_grants.sql is re-run, and MariaDB DDL cannot be rolled back, so a
 * backfill here would leave a half-applied migration. Deploy order:
 *   1. php artisan migrate
 *   2. re-run restrict_app_grants.sql on every database
 *   3. php artisan managers:backfill-item-types   (gives every existing manager both kinds)
 * Skipping step 3 leaves every manager without kinds, so none can see or decide requisitions.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_item_types', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 20); // 'chemical' | 'consumable' (App\Domain\Shared\ItemType)
            $table->primary(['user_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_item_types');
    }
};
