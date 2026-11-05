<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Services;

use App\Models\Container;
use App\Models\ImsLot;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a branch holds of an item, in the item's own base unit. Expired stock never counts.
 *
 * ITM-2610-062: users may SEE the branch's IMS store (`ims`, and `total` = working + ims, for display only) but
 * may only ORDER / dispense from working stock. `orderable` (= `working`) is the one number every
 * "may this be requested / issued" decision must use; never decide on `total`.
 */
final class ShopAvailability
{
    /**
     * Items with anything usable in this branch — the "shop shelf". IMS-only items stay on it so people can see
     * them (flagged "in the central store, not yet orderable").
     *
     * @return Builder<Item>
     */
    public function shelf(int $labId): Builder
    {
        // Science (loan-and-return) materials never appear on the shelf: they are lent, not requisitioned.
        return Item::query()
            ->requisitionable()
            ->where('is_active', true)
            ->whereNotNull('base_unit_id')
            ->where(function (Builder $q) use ($labId) {
                $q->whereHas('containers', fn (Builder $c) => $this->usableContainers($c, $labId))
                    ->orWhereHas('imsLots', fn (Builder $l) => $this->usableLots($l, $labId));
            });
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array{working: numeric-string, ims: numeric-string, total: numeric-string, orderable: numeric-string}>
     */
    public function forItems(array $itemIds, int $labId): array
    {
        /** @var array<int, numeric-string> $working */
        $working = [];
        /** @var array<int, numeric-string> $ims */
        $ims = [];
        foreach ($itemIds as $id) {
            $working[$id] = '0.000000';
            $ims[$id] = '0.000000';
        }

        if ($itemIds !== []) {
            $containerQuery = Container::query()->whereIn('item_id', $itemIds);
            $this->usableContainers($containerQuery, $labId);
            foreach ($containerQuery->get(['item_id', 'remaining_qty_base']) as $container) {
                $working[$container->item_id] = bcadd($working[$container->item_id], $container->remaining_qty_base, 6);
            }

            $lotQuery = ImsLot::query()->whereIn('item_id', $itemIds);
            $this->usableLots($lotQuery, $labId);
            foreach ($lotQuery->get(['item_id', 'qty_remaining_base']) as $lot) {
                $ims[$lot->item_id] = bcadd($ims[$lot->item_id], $lot->qty_remaining_base, 6);
            }
        }

        $result = [];
        foreach ($itemIds as $id) {
            $result[$id] = [
                'working' => $working[$id],
                'ims' => $ims[$id],
                'total' => bcadd($working[$id], $ims[$id], 6),
                'orderable' => $working[$id],
            ];
        }

        return $result;
    }

    /** @return array{working: numeric-string, ims: numeric-string, total: numeric-string, orderable: numeric-string} */
    public function forItem(Item $item, int $labId): array
    {
        return $this->forItems([$item->id], $labId)[$item->id];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function usableContainers(Builder $query, int $labId): void
    {
        $query->whereIn('status', ['SEALED', 'IN_USE'])
            ->where('remaining_qty_base', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->whereHas('location', fn (Builder $l) => $l->where('lab_id', $labId));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function usableLots(Builder $query, int $labId): void
    {
        $query->where('lab_id', $labId)
            ->where('qty_remaining_base', '>', 0)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()));
    }
}
