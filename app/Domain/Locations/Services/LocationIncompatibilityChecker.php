<?php

declare(strict_types=1);

namespace App\Domain\Locations\Services;

/**
 * BR-10: warn (never block) when two conflicting storage classes end up in the
 * same physical storage area. The pairing table is the whole rule — kept as a
 * pure, order-independent check so it can be reused both here (location tree
 * configuration) and later at goods-receiving/container placement (T-022),
 * once items are actually assigned to a location.
 */
final class LocationIncompatibilityChecker
{
    /** @var list<array{0: string, 1: string}> */
    private const CONFLICTS = [
        ['ACID', 'BASE'],
        ['FLAMMABLE', 'OXIDIZER'],
        ['TOXIC', 'FOOD_GRADE'],
    ];

    public function conflicts(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        foreach (self::CONFLICTS as [$x, $y]) {
            if (($a === $x && $b === $y) || ($a === $y && $b === $x)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<string|null>  $storageClasses
     * @return list<string> distinct storage classes that conflict with $subject
     */
    public function conflictsWithAny(?string $subject, iterable $storageClasses): array
    {
        $found = [];

        foreach ($storageClasses as $class) {
            if ($class !== null && $this->conflicts($subject, $class) && ! in_array($class, $found, true)) {
                $found[] = $class;
            }
        }

        return $found;
    }
}
