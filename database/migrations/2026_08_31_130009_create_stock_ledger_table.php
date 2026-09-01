<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items');
            $table->foreignId('container_id')->nullable()->constrained('containers');
            $table->date('txn_date');
            $table->enum('txn_type', [
                'OPENING', 'RECEIVE', 'ISSUE', 'RETURN', 'ADJUST_IN',
                'ADJUST_OUT', 'DISPOSE', 'TRANSFER_IN', 'TRANSFER_OUT',
            ]);
            $table->string('ref_type', 32)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('ref_doc_no', 64)->nullable();
            $table->decimal('qty_in_base', 18, 6)->default(0);
            $table->decimal('qty_out_base', 18, 6)->default(0);
            $table->decimal('balance_base', 18, 6);
            $table->unsignedSmallInteger('display_unit_id');
            $table->foreignId('issuer_id')->nullable()->constrained('users');
            $table->foreignId('receiver_id')->nullable()->constrained('users');
            $table->string('receiver_name', 255)->nullable();
            $table->char('signature_hash', 64)->nullable();
            $table->string('remark', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('created_at', 6);
            $table->char('prev_row_hash', 64)->nullable();
            $table->char('row_hash', 64);

            $table->foreign('display_unit_id')->references('id')->on('units');
            $table->index(['item_id', 'txn_date', 'id'], 'idx_ledger_item_date');
            $table->index(['ref_type', 'ref_id'], 'idx_ledger_ref');
            $table->index('container_id', 'idx_ledger_container');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_inout CHECK (
                (qty_in_base > 0 AND qty_out_base = 0) OR
                (qty_out_base > 0 AND qty_in_base = 0) OR
                (qty_in_base = 0 AND qty_out_base = 0 AND txn_type = 'OPENING')
            )
        SQL);

        DB::statement(
            'ALTER TABLE stock_ledger ADD CONSTRAINT chk_ledger_balance CHECK (balance_base >= 0)'
        );

        // Defense in depth #2 against AGENT RULE #6 (never UPDATE/DELETE stock_ledger).
        // #1 is the DB grant in T-027 (cmis_app has no UPDATE/DELETE on this table).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_ledger_no_update BEFORE UPDATE ON stock_ledger
            FOR EACH ROW BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_ledger is append-only';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON stock_ledger
            FOR EACH ROW BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_ledger is append-only';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_ledger_no_delete');
        Schema::dropIfExists('stock_ledger');
    }
};
