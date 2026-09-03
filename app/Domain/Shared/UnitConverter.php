<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Inventory\Exceptions\MissingDensityException;
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
}
