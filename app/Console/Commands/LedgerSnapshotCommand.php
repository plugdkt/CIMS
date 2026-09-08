<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\LedgerSnapshotService;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** T-047: `php artisan ledger:snapshot` — monthly balance snapshot (performance). */
final class LedgerSnapshotCommand extends Command
{
    protected $signature = 'ledger:snapshot {month? : Gregorian YYYY-MM, defaults to last calendar month}';

    protected $description = 'สร้าง/อัปเดต snapshot ยอดคงเหลือรายเดือนของบัญชีคุมวัสดุ (ledger_snapshots)';

    public function handle(LedgerSnapshotService $service): int
    {
        $monthArg = $this->argument('month');
        $month = now()->subMonthNoOverflow()->startOfMonth();
        if (is_string($monthArg)) {
            try {
                /** @var Carbon $parsed */
                $parsed = Carbon::createFromFormat('Y-m', $monthArg);
                $month = $parsed->startOfMonth();
            } catch (InvalidFormatException) {
                $this->error("รูปแบบเดือนไม่ถูกต้อง: {$monthArg} (ต้องเป็น YYYY-MM)");

                return self::FAILURE;
            }
        }

        $snapshots = $service->generateForPeriod($month);

        $this->info("สร้าง snapshot สำเร็จ {$snapshots->count()} รายการ สำหรับเดือน {$month->format('Y-m')}");

        return self::SUCCESS;
    }
}
