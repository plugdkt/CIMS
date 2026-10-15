<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Shared\UnitConverter;
use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsLotGroup;
use App\Models\Item;
use App\Models\ImsMovement;
use App\Models\ImsReceipt;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * IMS = a branch's central-store stock. Two steps own all the rules:
 *  - {@see confirm()}: a checked purchase document becomes lots (and RECEIVE movements);
 *  - {@see transferToWorkingStock()}: the university-store withdrawal is cut off IMS and,
 *    in the same transaction, becomes working-stock containers — requesters can only ever
 *    request from working stock, never from IMS directly.
 */
final class ImsService
{
    private const SCALE = 6;

    public function __construct(
        private readonly UnitConverter $converter,
        private readonly BaseUnitAdopter $adopter,
        private readonly WorkingStockService $workingStock,
    ) {
    }

    /**
     * @param  list<int>|null  $lineIds  confirm only these lines; null = every line still waiting.
     *                                   The receipt turns CONFIRMED once no waiting line is left.
     */
    public function confirm(ImsReceipt $receipt, User $user, ?array $lineIds = null): void
    {
        DB::transaction(function () use ($receipt, $user, $lineIds) {
            $locked = ImsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ImsException(__('ims.error.not_draft'));
            }

            $query = $locked->lines()->whereNull('ims_lot_id')->with(['item.packageUnit', 'unit']);
            if ($lineIds !== null) {
                $query->whereIn('id', $lineIds);
            }
            $lines = $query->get();
            if ($lines->isEmpty()) {
                throw new ImsException(__('ims.error.no_lines'));
            }

            // Validate every line before writing any of them — a half-confirmed document
            // would leave lots behind that the manager never saw as "confirmed".
            foreach ($lines as $line) {
                if ($line->item === null || $line->resolvedQuantity() === null) {
                    throw new ImsException(__('ims.error.line_incomplete', ['line' => $line->line_no]));
                }
            }

            foreach ($lines as $line) {
                $item = $line->item;
                $resolved = $line->resolvedQuantity();
                if ($item === null || $resolved === null) {
                    continue;
                }
                $unit = $resolved['unit'];

                $this->adopter->adoptIfUnused($item, $unit);

                /** @var numeric-string $qtyBase */
                $qtyBase = $this->converter->toItemBase($item, $unit, $resolved['qty']);

                $packQty = $line->pack_qty;
                $packSizeBase = ($packQty !== null && bccomp($packQty, '0', self::SCALE) > 0)
                    ? bcdiv($qtyBase, $packQty, self::SCALE)
                    : null;

                $lot = ImsLot::create([
                    'lab_id' => $locked->lab_id,
                    'item_id' => $item->id,
                    'ims_receipt_id' => $locked->id,
                    'doc_no' => $locked->doc_no,
                    'lot_no' => $line->lot_no,
                    'fiscal_year' => $locked->fiscal_year,
                    'purchase_round' => $locked->purchase_round,
                    'unit_price' => $line->unit_price,
                    'pack_size_base' => $packSizeBase,
                    'expiry_date' => $line->expiry_date,
                    'qty_received_base' => $qtyBase,
                    'qty_remaining_base' => $qtyBase,
                    'received_at' => now()->toDateString(),
                ]);
                $line->fill(['ims_lot_id' => $lot->id])->save();

                ImsMovement::create([
                    'ims_lot_id' => $lot->id,
                    'type' => 'RECEIVE',
                    'qty_base' => $qtyBase,
                    'balance_after_base' => $qtyBase,
                    'display_unit_id' => $unit->id,
                    'ref_doc_no' => $locked->doc_no,
                    'created_by' => $user->id,
                ]);
            }

            // Lines left behind (incomplete, or simply not selected) keep the document a draft.
            if (! $locked->lines()->whereNull('ims_lot_id')->exists()) {
                $locked->fill([
                    'status' => 'CONFIRMED',
                    'confirmed_by' => $user->id,
                    'confirmed_at' => now(),
                ])->save();
            }
        }, 3);
    }

    /**
     * @param  string  $imsDocNo  the withdrawal document number from the university's system —
     *                            stored as the ledger remark ("แหล่งที่มา"), per the agreed convention
     * @param  numeric-string|null  $qty  BULK: the whole quantity
     * @param  numeric-string|null  $qtyPerContainer  CONTAINER mode
     * @return list<Container>
     */
    public function transferToWorkingStock(
        ImsLot $lot,
        User $user,
        Unit $unit,
        Location $location,
        string $trackingType,
        ?string $qty,
        ?int $containerCount,
        ?string $qtyPerContainer,
        string $imsDocNo,
        ?string $remark = null,
        ?int $sourceGroupId = null,
    ): array {
        if ($location->lab_id !== $lot->lab_id) {
            throw new ImsException(__('ims.error.location_other_branch'));
        }

        return DB::transaction(function () use ($lot, $user, $unit, $location, $trackingType, $qty, $containerCount, $qtyPerContainer, $imsDocNo, $remark, $sourceGroupId) {
            $locked = ImsLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $item = $locked->item()->firstOrFail();

            $isContainer = $trackingType === 'CONTAINER';
            $count = $isContainer ? (int) $containerCount : 1;
            /** @var numeric-string $perQty */
            $perQty = (string) ($isContainer ? $qtyPerContainer : $qty);

            if ($count < 1 || bccomp($perQty, '0', self::SCALE) <= 0) {
                throw new ImsException(__('ims.error.qty_invalid'));
            }

            /** @var numeric-string $perBase */
            $perBase = $this->converter->toItemBase($item, $unit, $perQty);
            $totalBase = bcmul($perBase, (string) $count, self::SCALE);

            if (bccomp($totalBase, $locked->qty_remaining_base, self::SCALE) > 0) {
                throw new ImsException(__('ims.error.exceeds_lot_balance'));
            }

            $sourceGroup = null;
            if ($sourceGroupId !== null) {
                $sourceGroup = $locked->groups()->whereKey($sourceGroupId)->first();
                if ($sourceGroup === null) {
                    throw new ImsException(__('ims.error.group_not_found'));
                }
            }

            $containers = $this->workingStock->receive(
                $item,
                $unit,
                $location,
                $trackingType,
                $perQty,
                $count,
                $locked->lot_no,
                ($sourceGroup->expiry_date ?? $locked->expiry_date)?->toDateString(),
                $imsDocNo,
                (int) $user->id,
                $locked->id,
                $this->containerPrice($locked, $perBase),
            );

            $balance = bcsub($locked->qty_remaining_base, $totalBase, self::SCALE);
            $locked->qty_remaining_base = $balance;
            $locked->save();
            $this->reconcileGroups($locked, $sourceGroup, $totalBase);

            ImsMovement::create([
                'ims_lot_id' => $locked->id,
                'type' => 'ISSUE',
                'qty_base' => $totalBase,
                'balance_after_base' => $balance,
                'display_unit_id' => $unit->id,
                'ref_doc_no' => $imsDocNo,
                'remark' => $remark,
                'created_by' => $user->id,
            ]);

            return $containers;
        }, 3);
    }

    /**
     * Makes sure a branch's working stock can cover `$neededBase` for an item, by cutting the
     * shortfall from IMS lots (soonest-expiring first, expired lots never) into whole packs — the
     * physical bottle is what gets handed over, and what is left of it stays in working stock.
     * A lot with no recorded pack size is cut as one bulk quantity. Nothing happens when working
     * stock already covers the need. Returns the new working-stock containers.
     *
     * @param  numeric-string  $neededBase  the total the dispenser is about to take, in the item's base unit
     * @param  numeric-string  $workingBase  what the branch's usable working stock already holds
     * @return list<Container>
     */
    public function provisionWorkingStock(Item $item, int $labId, string $neededBase, string $workingBase, User $user, string $refDocNo): array
    {
        $short = bcsub($neededBase, $workingBase, self::SCALE);
        if (bccomp($short, '0', self::SCALE) <= 0) {
            return [];
        }

        $baseUnit = $item->baseUnit()->first();
        if ($baseUnit === null) {
            throw new ImsException(__('ims.error.no_base_unit'));
        }

        return DB::transaction(function () use ($item, $labId, $short, $user, $refDocNo, $baseUnit) {
            $lots = ImsLot::where('item_id', $item->id)
                ->where('lab_id', $labId)
                ->where('qty_remaining_base', '>', 0)
                ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
                ->orderByRaw('expiry_date is null')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var list<Container> $created */
            $created = [];
            $left = $short;

            foreach ($lots as $lot) {
                if (bccomp($left, '0', self::SCALE) <= 0) {
                    break;
                }

                $location = $lot->location_id !== null ? Location::find($lot->location_id) : null;
                if ($location === null) {
                    throw new ImsException(__('ims.error.lot_needs_location', ['lot' => $lot->lot_no ?: $item->item_code]));
                }

                /** @var numeric-string $remaining */
                $remaining = $lot->qty_remaining_base;
                $packSize = $lot->pack_size_base;

                if ($packSize === null || bccomp($packSize, '0', self::SCALE) <= 0) {
                    $take = bccomp($remaining, $left, self::SCALE) < 0 ? $remaining : $left;
                    $created = array_merge($created, $this->transferToWorkingStock($lot, $user, $baseUnit, $location, 'BULK', $take, null, null, $refDocNo, __('ims.auto_cut_remark')));
                    $left = bcsub($left, $take, self::SCALE);

                    continue;
                }

                // Whole packs first; a last part-pack (what is left over in the lot) can be cut too.
                $packsNeeded = (int) ceil((float) bcdiv($left, $packSize, 6));
                $packsAvailable = (int) bcdiv($remaining, $packSize, 0);
                $packs = min($packsNeeded, $packsAvailable);

                if ($packs > 0) {
                    $created = array_merge($created, $this->transferToWorkingStock($lot, $user, $baseUnit, $location, 'CONTAINER', null, $packs, $packSize, $refDocNo, __('ims.auto_cut_remark')));
                    $left = bcsub($left, bcmul($packSize, (string) $packs, self::SCALE), self::SCALE);
                }

                $lot->refresh();
                /** @var numeric-string $afterRemaining */
                $afterRemaining = $lot->qty_remaining_base;
                if (bccomp($left, '0', self::SCALE) > 0 && bccomp($afterRemaining, '0', self::SCALE) > 0 && bccomp($afterRemaining, $packSize, self::SCALE) < 0) {
                    $created = array_merge($created, $this->transferToWorkingStock($lot, $user, $baseUnit, $location, 'CONTAINER', null, 1, $afterRemaining, $refDocNo, __('ims.auto_cut_remark')));
                    $left = bcsub($left, $afterRemaining, self::SCALE);
                }
            }

            return $created;
        }, 3);
    }

    /**
     * Adds or edits a group of packs sharing one place and expiry date. The groups together can
     * never claim more packs than the lot still holds.
     *
     * @param  numeric-string  $packCount
     */
    public function saveGroup(ImsLot $lot, ?ImsLotGroup $group, string $packCount, ?int $locationId, ?string $expiryDate, ?string $remark): ImsLotGroup
    {
        return DB::transaction(function () use ($lot, $group, $packCount, $locationId, $expiryDate, $remark) {
            $locked = ImsLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();

            $remaining = $locked->packsRemainingExact();
            if ($remaining === null) {
                throw new ImsException(__('ims.error.groups_need_pack_size'));
            }
            if ($group !== null && $group->ims_lot_id !== $locked->id) {
                throw new ImsException(__('ims.error.group_not_found'));
            }
            if ($locationId !== null && ! Location::whereKey($locationId)->where('lab_id', $locked->lab_id)->exists()) {
                throw new ImsException(__('ims.error.location_other_branch'));
            }

            /** @var numeric-string $others */
            $others = $locked->groups()->get()
                ->reject(fn (ImsLotGroup $g) => $group !== null && $g->id === $group->id)
                ->reduce(fn (string $carry, ImsLotGroup $g) => bcadd($carry, $g->pack_count, 4), '0');
            if (bccomp(bcadd($others, $packCount, 4), $remaining, 4) > 0) {
                throw new ImsException(__('ims.error.groups_exceed', ['packs' => rtrim(rtrim($remaining, '0'), '.') ?: '0']));
            }

            $attributes = [
                'pack_count' => $packCount,
                'location_id' => $locationId,
                'expiry_date' => $expiryDate,
                'remark' => $remark,
            ];

            if ($group === null) {
                return $locked->groups()->create($attributes);
            }

            $group->fill($attributes)->save();

            return $group;
        }, 3);
    }

    /**
     * After stock leaves a lot: take the packs off the group they came from (if one was named),
     * then, if the groups still claim more than what is left, trim the newest ones. Emptied
     * groups are dropped.
     */
    private function reconcileGroups(ImsLot $lot, ?ImsLotGroup $source, string $totalBase): void
    {
        $remaining = $lot->packsRemainingExact();
        $packSize = $lot->pack_size_base;
        if ($remaining === null || $packSize === null) {
            return;
        }

        if ($source !== null) {
            $taken = bcdiv($totalBase, $packSize, 4);
            $source->pack_count = bccomp($source->pack_count, $taken, 4) > 0 ? bcsub($source->pack_count, $taken, 4) : '0';
            $source->save();
        }

        $groups = $lot->groups()->get();
        /** @var numeric-string $placed */
        $placed = $groups->reduce(fn (string $carry, ImsLotGroup $g) => bcadd($carry, $g->pack_count, 4), '0');
        $excess = bcsub($placed, $remaining, 4);

        foreach ($groups->reverse() as $group) {
            if (bccomp($excess, '0', 4) > 0) {
                $cut = bccomp($group->pack_count, $excess, 4) < 0 ? $group->pack_count : $excess;
                $group->pack_count = bcsub($group->pack_count, $cut, 4);
                $excess = bcsub($excess, $cut, 4);
                $group->save();
            }
            if (bccomp($group->pack_count, '0', 4) <= 0) {
                $group->delete();
            }
        }
    }

    /**
     * The lot's `unit_price` is per pack (ขวด/แพ็ค); a working-stock bottle that is not exactly
     * one pack is priced in proportion to the share of a pack it holds.
     *
     * @param  numeric-string  $containerQtyBase
     */
    private function containerPrice(ImsLot $lot, string $containerQtyBase): ?string
    {
        if ($lot->unit_price === null) {
            return null;
        }

        $packSize = $lot->pack_size_base;
        if ($packSize === null || bccomp($packSize, '0', self::SCALE) <= 0) {
            return $lot->unit_price;
        }

        return bcdiv(bcmul($lot->unit_price, $containerQtyBase, self::SCALE), $packSize, 2);
    }
}
