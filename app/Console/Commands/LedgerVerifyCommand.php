<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\LedgerHasher;
use App\Models\Item;
use App\Models\StockLedger;
use Illuminate\Console\Command;

/** BR-08: `php artisan ledger:verify` — checks every item's hash chain, reports broken rows. */
final class LedgerVerifyCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'ตรวจสอบความสมบูรณ์ของ hash chain ในบัญชีคุมวัสดุ (stock_ledger) ทุกรายการ';

    public function handle(LedgerHasher $hasher): int
    {
        $itemIds = StockLedger::query()->distinct()->pluck('item_id');

        if ($itemIds->isEmpty()) {
            $this->info('ไม่มีข้อมูลในบัญชีคุม (stock_ledger ว่างเปล่า)');

            return self::SUCCESS;
        }

        $itemNames = Item::whereIn('id', $itemIds)->pluck('name_th', 'id');
        $allOk = true;

        foreach ($itemIds as $itemId) {
            $result = $hasher->verifyChain($itemId);
            $label = $itemNames->get($itemId, "#{$itemId}");

            if ($result['ok']) {
                $this->info("[OK] {$label}");
            } else {
                $allOk = false;
                $this->error("[BROKEN] {$label} — แถวที่ผิด id={$result['broken_at']}");
            }
        }

        if ($allOk) {
            $this->newLine();
            $this->info('ตรวจสอบครบทุกรายการ: chain สมบูรณ์ 100%');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('พบ chain ที่ผิดปกติ — ดูรายละเอียดด้านบน');

        return self::FAILURE;
    }
}
