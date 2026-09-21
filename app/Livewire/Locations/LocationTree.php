<?php

declare(strict_types=1);

namespace App\Livewire\Locations;

use App\Domain\Locations\Services\LocationIncompatibilityChecker;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * FR-MD-04: informational-level tree view (BUILDING/ROOM/CABINET/SHELF, freely
 * nested — see LocationRequest). BR-10: flags a location whose own storage_class
 * conflicts with its parent/siblings (see LocationService).
 *
 * User-requested 2026-09-21: each branch's tree is independent and private — the
 * list is scoped to the viewer's own `lab_id` (every `location.manage` holder is a
 * branch manager, see User::isBranchManager(), so there is no "view every branch"
 * mode to preserve here, unlike some other lab-scoped list).
 */
#[Layout('components.layout')]
final class LocationTree extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Location::class);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->lab_id === null) {
            return view('livewire.locations.location-tree', [
                'roots' => collect(),
                'byParent' => collect(),
                'conflictIds' => [],
                'canManage' => false,
                'noOwnLab' => true,
            ]);
        }

        $locations = Location::where('lab_id', $user->lab_id)->orderBy('name')->get();
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
            'canManage' => $user->can('create', Location::class),
            'noOwnLab' => false,
        ]);
    }
}
