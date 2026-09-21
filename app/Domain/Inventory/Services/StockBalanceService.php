<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\Item;
use App\Models\StockLedger;

/**
 * A single place for "what's this item's current stock balance, and is it below its
 * reorder point right now" — the same `StockLedger::where('item_id', ...)->
 * orderByDesc('id')->value('balance_base')` query this app already had duplicated
 * across `RequisitionController::itemBalance()`, `DashboardService`,
 * `BelowReorderPointExport`, `ReportsDashboard`, `NotifyReorderPointCommand`, and
 * `ItemStockSummaryExport` (none of them shared it). Extracted 2026-09-21 for two new
 * consumers (the requisition review page, and an issue-time low-stock warning) rather
 * than adding an 8th inline copy — the five pre-existing call sites are left as-is,
 * already tested and working; not a blanket refactor.
 */
final class StockBalanceService
{
    /** @return numeric-string */
    public function currentBalance(Item $item): string
    {
        return StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000';
    }

    /**
     * BR-… reorder-point check: an item with no reorder point set (0, the column's
     * default) is never flagged — there's nothing to compare against. Same convention
     * as `BelowReorderPointExport`/`DashboardService::belowReorderPointCount()`.
     *
     * @param  numeric-string|null  $balance
     */
    public function isBelowReorderPoint(Item $item, ?string $balance = null): bool
    {
        if (bccomp($item->reorder_point_base, '0', 6) === 0) {
            return false;
        }

        $balance ??= $this->currentBalance($item);

        return bccomp($balance, $item->reorder_point_base, 6) < 0;
    }
}
