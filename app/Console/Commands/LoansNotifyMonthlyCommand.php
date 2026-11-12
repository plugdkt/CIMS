<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Loan\Services\LoanDailyNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-051 step 5 (spec section 14): `php artisan loans:notify-monthly`, scheduled on the 1st of each month.
 * Tells every ADMIN and every AUDITOR with a branch that the audit report of the month that just closed (write-offs,
 * adjustments, replacements) is ready. Once per person and month, however often it is run (LoanDailyNotifier).
 */
final class LoansNotifyMonthlyCommand extends Command
{
    protected $signature = 'loans:notify-monthly {--month= : YYYY-MM (default: the month that just closed)}';

    protected $description = 'แจ้ง ADMIN และผู้ตรวจสอบว่ารายงานตรวจสอบรายเดือนของงานยืม-คืนพร้อมแล้ว (รันซ้ำไม่ส่งซ้ำ)';

    public function handle(LoanDailyNotifier $notifier): int
    {
        if (! Schema::hasTable('loan_movements') || ! Schema::hasTable('loan_notice_log')) {
            $this->info((string) __('loans.daily_cmd.no_tables'));

            return self::SUCCESS;
        }

        $month = $this->option('month');
        if (! is_string($month) || $month === '') {
            $month = CarbonImmutable::today()->startOfMonth()->subMonth()->format('Y-m');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $this->error((string) __('loans.daily_cmd.bad_month'));

            return self::FAILURE;
        }

        $this->line((string) __('loans.daily_cmd.sent', ['type' => LoanDailyNotifier::TYPE_MONTHLY_AUDIT.' '.$month, 'count' => $notifier->runMonthly($month)]));

        return self::SUCCESS;
    }
}
