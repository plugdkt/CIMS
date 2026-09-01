<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('type', 64);
            $table->string('title', 255);
            $table->string('body', 1000)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'read_at'], 'idx_notif_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
