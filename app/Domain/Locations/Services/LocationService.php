<?php

declare(strict_types=1);

namespace App\Domain\Locations\Services;

use App\Models\Location;

/**
 * BR-10 wiring for the location tree itself: since no item is assigned to a
 * location yet in Phase 1 (that happens at goods-receiving/container placement,
 * T-022), this checks the one real signal available today — a location's own
 * `storage_class` against its parent and siblings — as an early, genuine safety
 * warning for whoever is laying out the storage plan. The same
 * LocationIncompatibilityChecker gets reused at T-022 once items are actually
 * placed into locations.
 */
final class LocationService
{
    public function __construct(private readonly LocationIncompatibilityChecker $checker)
    {
    }

    /** @return list<string> conflicting storage classes found among this location's parent/siblings */
    public function checkConflicts(Location $location): array
    {
        if ($location->storage_class === null) {
            return [];
        }

        $siblingClasses = Location::query()
            ->where('parent_id', $location->parent_id)
            ->where('id', '!=', $location->id)
            ->pluck('storage_class');

        $parentClass = $location->parent?->storage_class;

        return $this->checker->conflictsWithAny($location->storage_class, [...$siblingClasses, $parentClass]);
    }
}
