<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // v1.1.0: no local credentials — authentication is delegated entirely to the
        // MEDSCI ACC SSO. This table holds only local profile + CMIS-specific attributes
        // the SSO doesn't have (program, faculty, student code, advisor, lab).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('sso_subject', 64)->unique();
            $table->string('username', 64)->unique();
            $table->string('email', 255)->unique();
            $table->string('full_name', 255);
            $table->string('pos_name', 255)->nullable();
            $table->string('div_name', 255)->nullable();
            $table->binary('phone_encrypted', 512)->nullable();
            $table->binary('person_code_encrypted', 512)->nullable();
            // Nullable, not NOT NULL as spec's literal DDL shows: BR-11 is explicit that SSO
            // doesn't provide this — it's unknown until the post-login "complete profile" step.
            $table->enum('person_type', ['LECTURER', 'STAFF', 'STUDENT'])->nullable();
            $table->string('program', 255)->nullable();
            $table->string('faculty', 255)->nullable();
            $table->unsignedInteger('lab_id')->nullable();
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->dateTime('profile_completed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('last_sso_sync_at')->nullable();
            $table->timestamps();

            $table->foreign('lab_id')->references('id')->on('labs');
            $table->foreign('advisor_id')->references('id')->on('users');
            $table->index('advisor_id', 'idx_users_advisor');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 32)->unique();
            $table->string('name_th', 128);
        });

        // roles.id/permissions.id are SMALLINT UNSIGNED, so the FK columns below use
        // unsignedSmallInteger() rather than foreignId() (which is always BIGINT) —
        // InnoDB requires the referencing column type to match the referenced one exactly.
        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name_th', 128);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->unsignedSmallInteger('role_id');
            $table->unsignedSmallInteger('permission_id');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('permission_id')->references('id')->on('permissions');
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
