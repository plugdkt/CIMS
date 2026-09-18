<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sso_subject` was NOT NULL since T-004 on the assumption every `users` row is
 * created by a real SSO login — but `users:import-lab-assignments` now needs to
 * pre-create a full account (lab_id set) for a real person before their first login,
 * since there is no other way to create a `users` row ahead of time otherwise.
 * `UserProvisioningService::provision()` claims that row (sets `sso_subject`) the
 * moment the matching username actually logs in via SSO for real — see its own doc
 * comment. `sso_subject` stays `unique()`: MariaDB allows any number of NULLs in a
 * unique index, so multiple not-yet-claimed pre-created rows coexist safely.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_subject', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_subject', 64)->nullable(false)->change();
        });
    }
};
