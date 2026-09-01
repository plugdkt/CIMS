<?php

declare(strict_types=1);

namespace App\Livewire\Locations;

use App\Domain\Locations\Services\LocationIncompatibilityChecker;
use App\Models\Location;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** FR-MD-04: 4-level hierarchy tree view. BR-10: flags a location whose own
 *  storage_class conflicts with its parent/siblings (see LocationService). */
#[Layout('components.layout')]
final class LocationTree extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Location::class);
    }

    public function render(): View
    {
        $locations = Location::with('lab')->orderBy('name')->get();
        $byParent = $locations->groupBy('parent_id');
        $checker = app(LocationIncompatibilityChecker::class);

        $conflictIds = $locations
            ->filter(fn (Location $location) => $location->storage_class !== null)
            ->filter(function (Location $location) use ($locations, $checker) {
                $siblingClasses = $locations
                    ->where('parent_id', $location->parent_id)
                    ->where('id', '!=', $location->id)
                    ->pluck('storage_class');
                $parentClass = $locations->firstWhere('id', $location->parent_id)?->storage_class;

                return $checker->conflictsWithAny($location->storage_class, [...$siblingClasses, $parentClass]) !== [];
            })
            ->pluck('id')
            ->all();

        return view('livewire.locations.location-tree', [
            'roots' => $byParent->get(null, collect()),
            'byParent' => $byParent,
            'conflictIds' => $conflictIds,
            'canManage' => auth()->user()?->can('create', Location::class) ?? false,
        ]);
    }
}
