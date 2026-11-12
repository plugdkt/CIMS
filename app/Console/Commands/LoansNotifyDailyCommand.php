<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Loan\Services\LoanDailyNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 5 (spec section 10, N4-N7): `php artisan loans:notify-daily`, scheduled at 07:00.
 * Not picked up (N4), due soon (N5), overdue (N6), incidents waiting for a result (N7).
 *
 * NFR-LN-05: safe to run again on the same day (and to run twice at once) — nothing is sent a second time;
 * see LoanDailyNotifier. The second run prints zeros.
 */
final class LoansNotifyDailyCommand extends Command
{
    protected $signature = 'loans:notify-daily';

    protected $description = 'แจ้งเตือนรายวันของงานยืม-คืน (ยังไม่มารับของ ใกล้ครบกำหนด เกินกำหนด เหตุเสียหาย/สูญหายรอบันทึกผล) รันซ้ำในวันเดียวกันไม่ส่งซ้ำ';

    public function handle(LoanDailyNotifier $notifier): int
    {
        // Before the tables exist (deploy not finished) there is nothing to do: stay quiet, do not error.
        if (! Schema::hasTable('loans') || ! Schema::hasTable('loan_incidents') || ! Schema::hasTable('loan_notice_log')) {
            $this->info((string) __('loans.daily_cmd.no_tables'));

            return self::SUCCESS;
        }

        foreach ($notifier->runDaily() as $type => $count) {
            $this->line((string) __('loans.daily_cmd.sent', ['type' => $type, 'count' => $count]));
        }

        return self::SUCCESS;
    }
}
