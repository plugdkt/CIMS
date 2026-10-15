<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lot can be split into groups of packs that sit in different places / expire on different
 * dates ("3 bottles in cabinet A, 2 in cabinet B"). Packs not placed in any group fall back to
 * the lot's own location and expiry date.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('ims_lot_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('ims_lot_id')->constrained('ims_lots');
            $table->decimal('pack_count', 18, 4);
            $table->unsignedInteger('location_id')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('remark', 255)->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ims_lot_groups');
    }
};
