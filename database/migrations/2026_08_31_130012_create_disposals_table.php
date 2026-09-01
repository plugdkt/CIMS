<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('disposals', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('doc_no', 32)->unique();
            $table->foreignId('container_id')->constrained('containers');
            $table->decimal('qty_base', 18, 6);
            $table->enum('reason', ['EXPIRED', 'CONTAMINATED', 'DAMAGED', 'WASTE', 'OTHER']);
            $table->string('method', 255)->nullable();
            $table->date('disposal_date');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposals');
    }
};
