<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Inventory\Exceptions\MissingDensityException;
use App\Models\Item;
use App\Models\Unit;

final class UnitConverter
{
    private const SCALE = 6;

    /**
     * @param  numeric-string  $qty
     * @return numeric-string
     */
    public function toBase(string $qty, Unit $from): string
    {
        return bcmul($qty, (string) $from->factor_to_base, self::SCALE);
    }

    /**
     * @param  numeric-string  $qtyBase
     * @return numeric-string
     */
    public function fromBase(string $qtyBase, Unit $to): string
    {
        return bcdiv($qtyBase, (string) $to->factor_to_base, self::SCALE);
    }

    /**
     * แปลงข้ามมิติ MASS(mg) <-> VOLUME(uL)
     * หมายเหตุ: 1 g/mL = 1 mg/uL จึงใช้ density ได้ตรง ๆ ที่ระดับ base unit
     *
     * @param  numeric-string  $qtyBase
     * @return numeric-string
     */
    public function crossDimension(
        string $qtyBase,
        string $fromDimension,
        string $toDimension,
        ?float $densityGPerMl
    ): string {
        if ($fromDimension === $toDimension) {
            return $qtyBase;
        }
        if ($densityGPerMl === null || $densityGPerMl <= 0) {
            throw new MissingDensityException();
        }
        $d = (string) $densityGPerMl;

        return $fromDimension === 'MASS'
            ? bcdiv($qtyBase, $d, self::SCALE)
            : bcmul($qtyBase, $d, self::SCALE);
    }

    /**
     * Converts a quantity given in an arbitrary unit into an item's own `base_unit_id`
     * terms — crossing dimensions via the item's density when the given unit isn't the
     * same kind of measurement as the item's base unit (spec §5.1: every "_base" column
     * is stored in the item's own base unit, not the dimension's smallest unit — that
     * smallest unit is only the intermediate this conversion routes through).
     *
     * @param  numeric-string  $qty
     * @return numeric-string
     */
    public function toItemBase(Item $item, Unit $fromUnit, string $qty): string
    {
        /** @var Unit $itemBaseUnit */
        $itemBaseUnit = $item->baseUnit()->firstOrFail();
        $dimensionBaseQty = $this->toBase($qty, $fromUnit);

        if ($fromUnit->dimension === $itemBaseUnit->dimension) {
            return $this->fromBase($dimensionBaseQty, $itemBaseUnit);
        }

        $crossed = $this->crossDimension(
            $dimensionBaseQty,
            $fromUnit->dimension,
            $itemBaseUnit->dimension,
            $item->density_g_per_ml !== null ? (float) $item->density_g_per_ml : null,
        );

        return $this->fromBase($crossed, $itemBaseUnit);
    }
}
