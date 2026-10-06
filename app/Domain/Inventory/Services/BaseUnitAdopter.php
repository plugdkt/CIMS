<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\MissingDensityException;
use App\Domain\Shared\UnitConverter;
use App\Models\ImsLot;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Unit;

/**
 * User-reported 2026-09-22: an item stocked in as mL was stored and displayed as L. The
 * catalog import parses a base unit out of a free-text product name ("... 1 L /ขวด"),
 * which is a guess about packaging, not about the scale people actually work at. The unit
 * someone physically receives stock in is the authoritative one, so the first real
 * receipt adopts it.
 *
 * Every "_base" column is stored in the item's own base unit and both `stock_ledger` and
 * `ims_movements` are append-only, so once any row exists in either table that unit can
 * never be reinterpreted — `adoptIfUnused()` is a no-op from then on.
 */
final class BaseUnitAdopter
{
    public function __construct(private readonly UnitConverter $converter)
    {
    }

    public function adoptIfUnused(Item $item, Unit $unit): void
    {
        if ($item->base_unit_id !== null
            && (StockLedger::where('item_id', $item->id)->exists() || ImsLot::where('item_id', $item->id)->exists())
        ) {
            return;
        }

        $this->adopt($item, $unit);
    }

    private function adopt(Item $item, Unit $unit): void
    {
        $previousBaseUnit = $item->baseUnit()->first();

        $item->base_unit_id = $unit->id;
        $item->package_unit_id ??= $unit->id;

        /** @var numeric-string $reorderPoint */
        $reorderPoint = $item->reorder_point_base;

        if ($previousBaseUnit !== null
            && $previousBaseUnit->id !== $unit->id
            && bccomp($reorderPoint, '0', 6) > 0
        ) {
            try {
                $item->reorder_point_base = $this->converter->fromBase(
                    $this->converter->crossDimension(
                        $this->converter->toBase($reorderPoint, $previousBaseUnit),
                        $previousBaseUnit->dimension,
                        $unit->dimension,
                        $item->density_g_per_ml !== null ? (float) $item->density_g_per_ml : null,
                    ),
                    $unit,
                );
            } catch (MissingDensityException) {
                // Crossing MASS<->VOLUME without a density can't be converted. Keeping the
                // old number would silently assert a threshold nobody set (1 g becoming
                // 1 mL), so the alert is cleared instead and can be re-entered by hand.
                $item->reorder_point_base = '0.000000';
            }
        }

        $item->save();
        $item->refresh();
    }
}
