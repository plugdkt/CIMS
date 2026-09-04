<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notification\Services\NotificationService;
use App\Models\Container;
use Illuminate\Console\Command;

/**
 * FR-NT-05: daily — containers opened longer ago than their item's
 * `shelf_life_days_after_open` allows. In-app only (spec's own table has no "Email" for
 * this row, unlike FR-NT-01/02/03/04).
 */
final class NotifyShelfLifeCommand extends Command
{
    protected $signature = 'notifications:check-shelf-life';

    protected $description = 'แจ้งเตือนภาชนะที่เปิดใช้เกินอายุการใช้งานหลังเปิด (shelf_life_days_after_open)';

    public function handle(NotificationService $notifications): int
    {
        $recipients = $notifications->usersWithAnyPermission('item.manage', 'disposal.request');
        if ($recipients->isEmpty()) {
            return self::SUCCESS;
        }

        $containers = Container::whereIn('status', ['SEALED', 'IN_USE', 'QUARANTINE'])
            ->whereNotNull('opened_at')
            ->with('item')
            ->get()
            ->filter(function (Container $container) {
                $item = $container->item()->firstOrFail();

                return $item->shelf_life_days_after_open !== null
                    && $container->opened_at !== null
                    && $container->opened_at->copy()->addDays($item->shelf_life_days_after_open)->isPast();
            });

        foreach ($containers as $container) {
            $item = $container->item()->firstOrFail();
            foreach ($recipients as $recipient) {
                $notifications->notifyInApp(
                    $recipient,
                    'container.shelf_life',
                    __('notifications.shelf_life_title', ['item' => $item->name_th]),
                    __('notifications.shelf_life_body', ['item' => $item->name_th, 'barcode' => $container->barcode]),
                    route('items.show', $item),
                );
            }
        }

        $this->info("แจ้งเตือนภาชนะเกินอายุการใช้งานแล้ว {$containers->count()} รายการ");

        return self::SUCCESS;
    }
}
