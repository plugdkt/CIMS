<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Locations\Services\LocationService;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Every `location.manage` holder is a branch manager (LAB_MANAGER/AUDITOR — see
 * User::isBranchManager()) with their own `lab_id`; nobody else can reach these
 * actions. Since each branch's storage tree is now fully independent, there is no
 * "which lab" choice to offer — `lab` is always the acting manager's own, and the
 * `parents` list is always scoped to that same branch, never another one.
 */
final class LocationController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Location::class);

        /** @var User $manager */
        $manager = auth()->user();

        return view('locations.form', [
            'location' => new Location(),
            'lab' => $manager->lab,
            'parents' => Location::where('lab_id', $manager->lab_id)->orderBy('name')->get(),
        ]);
    }

    public function store(LocationRequest $request, LocationService $service): RedirectResponse
    {
        $location = Location::create($request->validated());
        $conflicts = $service->checkConflicts($location);

        return redirect()->route('locations.edit', $location)
            ->with('status', __('locations.saved'))
            ->with('conflicts', $conflicts);
    }

    public function edit(Location $location): View
    {
        $this->authorize('update', $location);

        return view('locations.form', [
            'location' => $location,
            'lab' => $location->lab,
            'parents' => Location::where('lab_id', $location->lab_id)
                ->where('id', '!=', $location->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(LocationRequest $request, Location $location, LocationService $service): RedirectResponse
    {
        $location->update($request->validated());
        $conflicts = $service->checkConflicts($location);

        return redirect()->route('locations.edit', $location)
            ->with('status', __('locations.saved'))
            ->with('conflicts', $conflicts);
    }
}
