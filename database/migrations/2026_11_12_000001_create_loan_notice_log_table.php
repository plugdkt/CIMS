<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 5 (NFR-LN-05): the "already sent today" record of the scheduled loan notices (N4-N7 and the
 * monthly audit summary). One row = one notice of one kind, to one person, about one subject, on one day.
 *
 * The UNIQUE key is the whole mechanism: the job INSERTs the row first and sends only when the insert really
 * added it, so a second run on the same day — or two runs at the same moment — cannot send the same notice twice
 * (the database decides, not a "look first, then write" check).
 *
 * An ordinary table (full DML; old rows are pruned by the job). No data access here. `cmis_app` has no rights on a
 * brand-new table until docker/mariadb/restrict_app_grants.sql is re-run (no edit of the script is needed), so
 * deploy is (1) php artisan migrate (2) re-run the grants script on every database.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('loan_notice_log', function (Blueprint $table) {
            $table->id();
            // The day the notice is about (for the monthly summary: the first day of the reported month).
            $table->date('notice_date');
            $table->string('type', 40);
            $table->foreignId('user_id')->constrained('users');
            // What it is about: "loan:<id>", "lab:<id>" or "month:<YYYY-MM>". No personal data.
            $table->string('subject', 40);
            $table->dateTime('created_at');

            $table->unique(['notice_date', 'type', 'user_id', 'subject'], 'uq_loan_notice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_notice_log');
    }
};
