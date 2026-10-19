<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Services\StoragePlaceOverview;
use App\Models\ImsLot;
use App\Models\Lab;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Read-only "where is everything kept" tab of the IMS area. Prices never appear here. */
final class ImsPlaceController extends Controller
{
    public function index(Request $request, StoragePlaceOverview $overview): View
    {
        $this->authorize('viewAny', ImsLot::class);

        /** @var User $user */
        $user = $request->user();
        $labs = $user->lab_id === null ? Lab::orderBy('name_th')->get() : collect();
        $labId = $user->lab_id ?? ($request->filled('lab') ? (int) $request->query('lab') : (int) ($labs->first()->id ?? 0));
        $search = trim((string) $request->query('search', ''));

        return view('ims.places.index', [
            'labs' => $labs,
            'labId' => $labId,
            'search' => $search,
        ] + $overview->index($labId, $search));
    }

    public function show(Request $request, Location $location, StoragePlaceOverview $overview): View
    {
        $this->authorize('viewAny', ImsLot::class);

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->lab_id === null || $user->lab_id === $location->lab_id, 403);

        $search = trim((string) $request->query('search', ''));

        return view('ims.places.show', [
            'place' => $location,
            'search' => $search,
            'canManage' => $user->can('ims.manage'),
        ] + $overview->contents($location, $search));
    }
}
