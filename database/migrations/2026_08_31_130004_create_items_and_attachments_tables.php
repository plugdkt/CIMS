<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('item_code', 32)->unique();
            $table->unsignedInteger('category_id');
            $table->string('name_th', 255);
            $table->string('name_en', 255)->nullable();
            $table->string('cas_no', 20)->nullable();
            $table->string('formula', 128)->nullable();
            $table->string('brand', 128)->nullable();
            $table->string('grade', 64)->nullable();
            $table->decimal('package_size', 18, 6)->nullable();
            $table->unsignedSmallInteger('package_unit_id')->nullable();
            $table->unsignedSmallInteger('sub_unit_id')->nullable();
            $table->unsignedSmallInteger('base_unit_id');
            $table->decimal('density_g_per_ml', 12, 6)->nullable();
            $table->decimal('reorder_point_base', 18, 6)->default(0);
            $table->boolean('is_controlled')->default(false);
            $table->string('control_class', 64)->nullable();
            $table->json('ghs_codes')->nullable();
            $table->json('h_statements')->nullable();
            $table->json('p_statements')->nullable();
            $table->string('storage_class', 64)->nullable();
            $table->unsignedSmallInteger('shelf_life_days_after_open')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('item_categories');
            $table->foreign('package_unit_id')->references('id')->on('units');
            $table->foreign('sub_unit_id')->references('id')->on('units');
            $table->foreign('base_unit_id')->references('id')->on('units');

            $table->index('cas_no', 'idx_items_cas');
            $table->index(['category_id', 'is_active'], 'idx_items_cat');
            $table->fullText(['name_th', 'name_en', 'brand'], 'ft_items');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('owner_type', 64);
            $table->unsignedBigInteger('owner_id');
            $table->enum('doc_type', ['SDS', 'INVOICE', 'PHOTO', 'OTHER']);
            $table->string('original_name', 255);
            $table->string('stored_name', 64);
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('version')->default(1);
            $table->date('revised_date')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->dateTime('created_at');

            $table->index(['owner_type', 'owner_id', 'doc_type'], 'idx_att_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('items');
    }
};
