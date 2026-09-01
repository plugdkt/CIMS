<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Locations\Services\LocationService;
use App\Http\Requests\LocationRequest;
use App\Models\Lab;
use App\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class LocationController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Location::class);

        return view('locations.form', [
            'location' => new Location(),
            'labs' => Lab::orderBy('name_th')->get(),
            'parents' => Location::orderBy('name')->get(),
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
            'labs' => Lab::orderBy('name_th')->get(),
            'parents' => Location::where('id', '!=', $location->id)->orderBy('name')->get(),
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
