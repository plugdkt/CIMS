<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Shared\UnitConverter;
use App\Models\Container;
use App\Models\ImsLot;
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

    public function confirm(ImsReceipt $receipt, User $user): void
    {
        DB::transaction(function () use ($receipt, $user) {
            $locked = ImsReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new ImsException(__('ims.error.not_draft'));
            }

            $lines = $locked->lines()->with(['item', 'unit'])->get();
            if ($lines->isEmpty()) {
                throw new ImsException(__('ims.error.no_lines'));
            }

            // Validate every line before writing any of them — a half-confirmed document
            // would leave lots behind that the manager never saw as "confirmed".
            foreach ($lines as $line) {
                if ($line->item === null || $line->unit === null || bccomp($line->qty, '0', self::SCALE) <= 0) {
                    throw new ImsException(__('ims.error.line_incomplete', ['line' => $line->line_no]));
                }
            }

            foreach ($lines as $line) {
                $item = $line->item;
                $unit = $line->unit;
                if ($item === null || $unit === null) {
                    continue;
                }

                $this->adopter->adoptIfUnused($item, $unit);

                /** @var numeric-string $qtyBase */
                $qtyBase = $this->converter->toItemBase($item, $unit, $line->qty);

                $lot = ImsLot::create([
                    'lab_id' => $locked->lab_id,
                    'item_id' => $item->id,
                    'ims_receipt_id' => $locked->id,
                    'doc_no' => $locked->doc_no,
                    'lot_no' => $line->lot_no,
                    'fiscal_year' => $locked->fiscal_year,
                    'purchase_round' => $locked->purchase_round,
                    'unit_price' => $line->unit_price,
                    'expiry_date' => $line->expiry_date,
                    'qty_received_base' => $qtyBase,
                    'qty_remaining_base' => $qtyBase,
                    'received_at' => now()->toDateString(),
                ]);

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

            $locked->fill([
                'status' => 'CONFIRMED',
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();
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
    ): array {
        if ($location->lab_id !== $lot->lab_id) {
            throw new ImsException(__('ims.error.location_other_branch'));
        }

        return DB::transaction(function () use ($lot, $user, $unit, $location, $trackingType, $qty, $containerCount, $qtyPerContainer, $imsDocNo, $remark) {
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

            $containers = $this->workingStock->receive(
                $item,
                $unit,
                $location,
                $trackingType,
                $perQty,
                $count,
                $locked->lot_no,
                $locked->expiry_date?->toDateString(),
                $imsDocNo,
                (int) $user->id,
                $locked->id,
                $locked->unit_price,
            );

            $balance = bcsub($locked->qty_remaining_base, $totalBase, self::SCALE);
            $locked->qty_remaining_base = $balance;
            $locked->save();

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
}
