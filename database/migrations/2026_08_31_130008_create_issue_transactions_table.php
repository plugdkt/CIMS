<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('requisition_item_id')->constrained('requisition_items');
            $table->foreignId('container_id')->constrained('containers');
            $table->decimal('qty_issued_base', 18, 6);
            $table->dateTime('issued_at');
            $table->foreignId('issuer_id')->constrained('users');
            $table->foreignId('receiver_id')->constrained('users');
            $table->char('signature_hash', 64)->nullable();
            $table->string('signature_image_path', 255)->nullable();
            $table->string('remark', 500)->nullable();

            $table->index('requisition_item_id', 'idx_issue_reqitem');
        });

        DB::statement(
            'ALTER TABLE issue_transactions ADD CONSTRAINT chk_issue_qty CHECK (qty_issued_base > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_transactions');
    }
};
