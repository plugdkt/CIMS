<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The university warehouse report counts stock in packs (ขวด/กล่อง) and prices per pack, not
 * in mL/g. A line therefore carries `pack_qty` (+ the price per pack in `unit_price`); its
 * measurable quantity is `pack_qty × the item's package size` unless the manager enters an
 * explicit `qty`/`unit_id` (needed when the catalog has no package size). Lots remember the
 * size of one pack in the item's base unit so a working-stock bottle can be priced
 * proportionally when it is cut from the lot.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ims_receipt_lines', function (Blueprint $table) {
            $table->decimal('pack_qty', 18, 6)->nullable()->after('lot_no');
            $table->decimal('qty', 18, 6)->nullable()->change();
        });

        Schema::table('ims_lots', function (Blueprint $table) {
            $table->decimal('pack_size_base', 18, 6)->nullable()->after('unit_price');
        });

        Schema::table('ims_receipts', function (Blueprint $table) {
            $table->json('import_summary')->nullable()->after('source_file_name');
            $table->string('source_file_hash', 64)->nullable()->after('import_summary');
            $table->index(['lab_id', 'source_file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('ims_receipts', function (Blueprint $table) {
            $table->dropIndex(['lab_id', 'source_file_hash']);
            $table->dropColumn(['import_summary', 'source_file_hash']);
        });
        Schema::table('ims_lots', function (Blueprint $table) {
            $table->dropColumn('pack_size_base');
        });
        Schema::table('ims_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('pack_qty');
        });
    }
};
