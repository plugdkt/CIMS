<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 6);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('username', 64)->nullable();
            $table->binary('ip_address', 16)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('action', 64);
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->enum('result', ['SUCCESS', 'FAILURE']);
            $table->string('message', 500)->nullable();

            $table->index('occurred_at', 'idx_audit_time');
            $table->index(['user_id', 'occurred_at'], 'idx_audit_user');
            $table->index(['entity_type', 'entity_id'], 'idx_audit_entity');
        });

        // AGENT RULE #6: audit_logs is append-only, same as stock_ledger. Spec's illustrative
        // DDL only showed the trigger pair for stock_ledger, but the rule text is explicit
        // that both tables are covered — so audit_logs gets the same defense-in-depth trigger.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
            FOR EACH ROW BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
            FOR EACH ROW BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_no_delete');
        Schema::dropIfExists('audit_logs');
    }
};
