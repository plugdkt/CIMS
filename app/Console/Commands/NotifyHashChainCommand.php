<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Services\LedgerHasher;
use App\Domain\Notification\Services\NotificationService;
use App\Models\Item;
use App\Models\StockLedger;
use Illuminate\Console\Command;

/**
 * FR-NT-06: hash chain (BR-08) integrity check, email-only, routed to ADMIN. Spec marks
 * this "Immediate", but nothing in the write path can ever produce a broken chain — every
 * write goes through `LedgerService::appendRow()`, which always computes the hash correctly,
 * and the DB grants (T-027) plus append-only triggers block any other write to
 * `stock_ledger`. A break can only come from something outside the app entirely (direct DB
 * tampering, a bad restore), which only a periodic scan can catch — so this runs on the same
 * schedule as the other daily checks rather than on a real "immediate" trigger that doesn't
 * exist. See CLAUDE.md.
 */
final class NotifyHashChainCommand extends Command
{
    protected $signature = 'notifications:check-hash-chain';

    protected $description = 'ตรวจสอบ hash chain ของบัญชีคุมทุกรายการ และแจ้งเตือน ADMIN ทางอีเมลหากพบความผิดปกติ';

    public function handle(LedgerHasher $hasher, NotificationService $notifications): int
    {
        $itemIds = StockLedger::query()->distinct()->pluck('item_id');
        $broken = [];

        foreach ($itemIds as $itemId) {
            $result = $hasher->verifyChain($itemId);
            if (! $result['ok']) {
                $broken[] = ['item_id' => $itemId, 'broken_at' => $result['broken_at']];
            }
        }

        if ($broken === []) {
            $this->info('ตรวจสอบครบทุกรายการ: chain สมบูรณ์ 100%');

            return self::SUCCESS;
        }

        $itemNames = Item::whereIn('id', array_column($broken, 'item_id'))->pluck('name_th', 'id');
        $lines = array_map(
            fn (array $row) => $itemNames->get($row['item_id'], "#{$row['item_id']}").' (row id='.$row['broken_at'].')',
            $broken,
        );

        foreach ($notifications->usersWithRole('ADMIN') as $admin) {
            $notifications->emailOnly(
                $admin,
                __('notifications.hash_chain_title'),
                __('notifications.hash_chain_body', ['items' => implode(', ', $lines)]),
                null,
            );
        }

        $this->error('พบ chain ที่ผิดปกติ '.count($broken).' รายการ — แจ้งเตือน ADMIN แล้ว');

        return self::FAILURE;
    }
}
