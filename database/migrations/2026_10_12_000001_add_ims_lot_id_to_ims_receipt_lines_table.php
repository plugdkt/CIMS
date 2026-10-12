<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt can now be confirmed line by line: a line that already became a lot points at it
 * and is frozen; the rest stay in the draft. Lines of receipts confirmed before this column
 * existed keep a NULL here and are treated as confirmed through their receipt's own status.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ims_receipt_lines', function (Blueprint $table) {
            $table->foreignId('ims_lot_id')->nullable()->after('remark')->constrained('ims_lots');
        });
    }

    public function down(): void
    {
        Schema::table('ims_receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ims_lot_id');
        });
    }
};
