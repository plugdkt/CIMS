<?php

declare(strict_types=1);

namespace App\Livewire\Ims;

use App\Domain\Inventory\Services\StoragePlaceOverview;
use App\Models\ImsLot;
use App\Models\ImsLotGroup;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * What is kept in one storage place, with live "type to find, press + to add" and a remove
 * button per row. Adding sets the lot's storage place; removing only clears it again (the
 * stock itself is never touched). Managers of the place's own branch only.
 */
final class PlaceContents extends Component
{
    public int $locationId;

    /** Search box for adding substances (live). */
    public string $term = '';

    /** Filter of the list below. */
    public string $filter = '';

    public function mount(int $locationId): void
    {
        $this->locationId = $locationId;
        $this->place();
    }

    public function add(int $lotId): void
    {
        $place = $this->place();
        $lot = $this->manageableLot($lotId, $place);

        $lot->forceFill(['location_id' => $place->id])->save();
    }

    public function removeLot(int $lotId): void
    {
        $lot = $this->manageableLot($lotId, $this->place());

        $lot->forceFill(['location_id' => null])->save();
    }

    public function removeGroup(int $groupId): void
    {
        $place = $this->place();
        $group = ImsLotGroup::findOrFail($groupId);
        $this->manageableLot($group->ims_lot_id, $place);

        $group->forceFill(['location_id' => null])->save();
    }

    public function render(StoragePlaceOverview $overview): View
    {
        $place = $this->place();
        $canManage = $this->canManage($place);
        $term = trim($this->term);

        // Lots of this branch matching what was typed, that are not already in this place.
        $candidates = $term === '' || ! $canManage ? collect() : ImsLot::query()
            ->with(['item.baseUnit', 'location'])
            ->where('lab_id', $place->lab_id)
            ->where('qty_remaining_base', '>', 0)
            ->search($term)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', '!=', $place->id))
            ->orderBy('item_id')
            ->limit(30)
            ->get();

        return view('livewire.ims.place-contents', [
            'place' => $place,
            'canManage' => $canManage,
            'candidates' => $candidates,
        ] + $overview->contents($place, trim($this->filter)));
    }

    private function place(): Location
    {
        $place = Location::findOrFail($this->locationId);

        /** @var User $user */
        $user = auth()->user();
        abort_unless($user->can('viewAny', ImsLot::class) && ($user->lab_id === null || $user->lab_id === $place->lab_id), 403);

        return $place;
    }

    private function canManage(Location $place): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->can('ims.manage') && $user->lab_id === $place->lab_id;
    }

    private function manageableLot(int $lotId, Location $place): ImsLot
    {
        abort_unless($this->canManage($place), 403);

        return ImsLot::where('id', $lotId)->where('lab_id', $place->lab_id)->firstOrFail();
    }
}
