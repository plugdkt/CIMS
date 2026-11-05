<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

/**
 * ITM-2610-062: which storage places a working-stock move may go to. The ONE place to change when a place is tied to
 * a working store (chemical / consumable) — `locations` has no such column yet (CEO decision pending, no migration),
 * so for now every place of the branch is offered whatever the kind. The server still checks the place belongs to the
 * lot's own branch (ImsService), so narrowing this later only needs the list and a matching server check here.
 */
final class WorkingStockDestinations
{
    /**
     * @param  string|null  $itemType  an App\Domain\Shared\ItemType slug; unused until places carry a store
     * @return Collection<int, Location>
     */
    public function forType(int $labId, ?string $itemType = null): Collection
    {
        return Location::query()->where('lab_id', $labId)->orderBy('code')->get();
    }

    public function allows(Location $location, int $labId, ?string $itemType = null): bool
    {
        return $this->forType($labId, $itemType)->contains('id', $location->id);
    }
}
