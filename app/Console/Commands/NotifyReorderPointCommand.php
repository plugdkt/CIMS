<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notification\Services\NotificationService;
use App\Models\Item;
use App\Models\StockLedger;
use Illuminate\Console\Command;

/** FR-NT-01: daily 07:00 — items whose current balance has dropped below their reorder point. */
final class NotifyReorderPointCommand extends Command
{
    protected $signature = 'notifications:check-reorder';

    protected $description = 'แจ้งเตือนรายการที่คงเหลือต่ำกว่าจุดสั่งซื้อ (reorder point)';

    public function handle(NotificationService $notifications): int
    {
        $recipients = $notifications->usersWithAnyPermission('item.manage');
        if ($recipients->isEmpty()) {
            return self::SUCCESS;
        }

        $lowItems = Item::where('is_active', true)->where('reorder_point_base', '>', 0)->get()
            ->filter(function (Item $item) {
                $balance = StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000';

                return bccomp($balance, $item->reorder_point_base, 6) < 0;
            });

        foreach ($lowItems as $item) {
            foreach ($recipients as $recipient) {
                $notifications->notifyInAppAndEmail(
                    $recipient,
                    'stock.reorder',
                    __('notifications.reorder_title', ['item' => $item->name_th]),
                    __('notifications.reorder_body', ['item' => $item->name_th, 'item_code' => $item->item_code]),
                    route('items.show', $item),
                );
            }
        }

        $this->info("แจ้งเตือนแล้ว {$lowItems->count()} รายการ");

        return self::SUCCESS;
    }
}
