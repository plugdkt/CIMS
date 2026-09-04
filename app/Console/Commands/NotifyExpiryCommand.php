<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notification\Services\NotificationService;
use App\Models\Container;
use Illuminate\Console\Command;

/**
 * FR-NT-02: daily 07:00 — containers reaching 90/30/7 days before their expiry date. Fires
 * on the exact day a container crosses one of those three thresholds (not "any day within"),
 * so each container is flagged at most three times over its life, not every day it's close.
 */
final class NotifyExpiryCommand extends Command
{
    private const THRESHOLDS_DAYS = [90, 30, 7];

    protected $signature = 'notifications:check-expiry';

    protected $description = 'แจ้งเตือนภาชนะที่ใกล้หมดอายุ (90/30/7 วัน)';

    public function handle(NotificationService $notifications): int
    {
        $recipients = $notifications->usersWithAnyPermission('item.manage', 'disposal.request');
        if ($recipients->isEmpty()) {
            return self::SUCCESS;
        }

        $today = now()->toDateString();
        $count = 0;

        foreach (self::THRESHOLDS_DAYS as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            $containers = Container::whereIn('status', ['SEALED', 'IN_USE', 'QUARANTINE'])
                ->whereDate('expiry_date', $targetDate)
                ->with('item')
                ->get();

            foreach ($containers as $container) {
                $item = $container->item()->firstOrFail();
                foreach ($recipients as $recipient) {
                    $notifications->notifyInAppAndEmail(
                        $recipient,
                        'stock.expiry',
                        __('notifications.expiry_title', ['item' => $item->name_th, 'days' => $days]),
                        __('notifications.expiry_body', [
                            'item' => $item->name_th,
                            'barcode' => $container->barcode,
                            'expiry_date' => $container->expiry_date?->format('d/m/Y'),
                        ]),
                        route('items.show', $item),
                    );
                }
                $count++;
            }
        }

        $this->info("[{$today}] แจ้งเตือนใกล้หมดอายุแล้ว {$count} ภาชนะ");

        return self::SUCCESS;
    }
}
