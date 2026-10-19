<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Inventory\Services\ImsService;
use App\Domain\Attachments\Exceptions\InfectedFileException;
use App\Domain\Attachments\Services\AttachmentUploadService;
use App\Http\Requests\ImsLotDetailsRequest;
use App\Http\Requests\ImsLotGroupRequest;
use App\Http\Requests\ImsLotLocateRequest;
use App\Http\Requests\ImsLotPhotoRequest;
use App\Http\Requests\ImsTransferRequest;
use App\Models\Attachment;
use App\Models\ImsLot;
use App\Models\ImsLotGroup;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImsLotController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ImsLot::class);

        /** @var User $user */
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $showEmpty = $request->boolean('show_empty');
        $categoryId = $request->filled('category') ? (int) $request->query('category') : null;
        $noLocation = $request->boolean('no_location');

        $lots = ImsLot::query()
            ->with(['item.baseUnit', 'item.category', 'lab', 'location', 'photos'])
            ->when(! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id))
            ->when($categoryId !== null, fn ($q) => $q->whereHas('item', fn ($i) => $i->where('category_id', $categoryId)))
            ->when(! $showEmpty, fn ($q) => $q->where('qty_remaining_base', '>', 0))
            ->when($noLocation, fn ($q) => $q->whereNull('location_id'))
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Lots still holding stock but with no storage place cannot be cut into working stock automatically.
        $unplacedCount = ImsLot::query()
            ->when(! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id))
            ->where('qty_remaining_base', '>', 0)
            ->whereNull('location_id')
            ->count();

        return view('ims.lots.index', [
            'noLocation' => $noLocation,
            'unplacedCount' => $unplacedCount,
            'lots' => $lots,
            'search' => $search,
            'showEmpty' => $showEmpty,
            'categoryId' => $categoryId,
            'categories' => ItemCategory::orderBy('id')->get(),
        ]);
    }

    /** Bulk "put these lots in this place": the branch manager picks a place, ticks the lots, saves once. */
    public function locateForm(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('ims.manage') && $user->lab_id !== null, 403);

        $search = trim((string) $request->query('search', ''));
        $showAll = $request->boolean('show_all');

        $lots = ImsLot::query()
            ->with(['item.baseUnit', 'location'])
            ->where('lab_id', $user->lab_id)
            ->where('qty_remaining_base', '>', 0)
            ->when(! $showAll, fn ($q) => $q->whereNull('location_id'))
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('item_id')
            ->orderBy('id')
            ->limit(500)
            ->get();

        return view('ims.lots.locate', [
            'lots' => $lots,
            'locations' => Location::where('lab_id', $user->lab_id)->orderBy('code')->get(),
            'search' => $search,
            'showAll' => $showAll,
        ]);
    }

    public function locateStore(ImsLotLocateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $count = ImsLot::whereIn('id', $data['lots'])
            ->where('lab_id', $user->lab_id)
            ->update(['location_id' => (int) $data['location_id']]);

        $status = __('ims.locate_saved', ['count' => $count]);
        $back = $request->input('return_place');
        $place = is_string($back) ? Location::where('ulid', $back)->where('lab_id', $user->lab_id)->first() : null;

        return ($place !== null
            ? redirect()->route('ims.places.show', $place)
            : redirect()->route('ims.lots.locate'))->with('status', $status);
    }

    public function transferForm(ImsLot $imsLot): View
    {
        $this->authorize('transfer', $imsLot);

        $imsLot->load(['item.baseUnit', 'lab']);

        $imsLot->load('groups.location');

        return view('ims.lots.transfer', [
            'lot' => $imsLot,
            'units' => Unit::orderBy('sort_order')->get(),
            'locations' => Location::where('lab_id', $imsLot->lab_id)->orderBy('name')->get(),
        ]);
    }

    public function transfer(ImsTransferRequest $request, ImsLot $imsLot, ImsService $service): RedirectResponse
    {
        $data = $request->validated();

        /** @var User $user */
        $user = $request->user();

        /** @var numeric-string|null $qty */
        $qty = isset($data['qty']) ? (string) $data['qty'] : null;
        /** @var numeric-string|null $qtyPerContainer */
        $qtyPerContainer = isset($data['qty_per_container']) ? (string) $data['qty_per_container'] : null;

        try {
            $containers = $service->transferToWorkingStock(
                $imsLot,
                $user,
                Unit::findOrFail((int) $data['unit_id']),
                Location::findOrFail((int) $data['location_id']),
                (string) $data['tracking_type'],
                $qty,
                isset($data['container_count']) ? (int) $data['container_count'] : null,
                $qtyPerContainer,
                (string) $data['ims_doc_no'],
                $data['remark'] ?? null,
                isset($data['source_group']) ? (int) $data['source_group'] : null,
            );
        } catch (ImsException $e) {
            return back()->withInput()->withErrors(['transfer' => $e->getMessage()]);
        }

        $redirect = redirect()->route('ims.lots.index')->with('status', __('ims.transferred'));

        if ($data['tracking_type'] === 'CONTAINER') {
            $redirect->with('label_container_ids', implode(',', array_map(fn ($c) => $c->id, $containers)));
        }

        return $redirect;
    }

    public function edit(ImsLot $imsLot): View
    {
        // Everyone who can see the lot gets its full details; the forms only render for its manager.
        $this->authorize('view', $imsLot);

        $imsLot->load(['item.baseUnit', 'item.category', 'lab', 'location', 'photos', 'groups.location']);

        $siblings = ImsLot::query()
            ->with(['item.baseUnit', 'location'])
            ->where('lab_id', $imsLot->lab_id)
            ->where('item_id', $imsLot->item_id)
            ->whereKeyNot($imsLot->id)
            ->where('qty_remaining_base', '>', 0)
            ->orderBy('expiry_date')
            ->get();

        return view('ims.lots.edit', [
            'lot' => $imsLot,
            'siblings' => $siblings,
            'locations' => Location::where('lab_id', $imsLot->lab_id)->orderBy('name')->get(),
        ]);
    }

    public function update(ImsLotDetailsRequest $request, ImsLot $imsLot): RedirectResponse
    {
        $data = $request->validated();
        $imsLot->fill([
            'lot_no' => $data['lot_no'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'fiscal_year' => $data['fiscal_year'] ?? null,
            'purchase_round' => $data['purchase_round'] ?? null,
            'unit_price' => $data['unit_price'] ?? null,
            'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
        ])->save();

        return redirect()->route('ims.lots.edit', $imsLot)->with('status', __('ims.lot_details_saved'));
    }

    public function storeGroup(ImsLotGroupRequest $request, ImsLot $imsLot, ImsService $service): RedirectResponse
    {
        return $this->saveGroup($request, $imsLot, null, $service);
    }

    public function updateGroup(ImsLotGroupRequest $request, ImsLot $imsLot, ImsLotGroup $group, ImsService $service): RedirectResponse
    {
        abort_unless($group->ims_lot_id === $imsLot->id, 404);

        return $this->saveGroup($request, $imsLot, $group, $service);
    }

    public function destroyGroup(ImsLot $imsLot, ImsLotGroup $group): RedirectResponse
    {
        $this->authorize('manage', $imsLot);
        abort_unless($group->ims_lot_id === $imsLot->id, 404);

        $group->delete();

        return redirect()->route('ims.lots.edit', $imsLot)->with('status', __('ims.group_removed'));
    }

    private function saveGroup(ImsLotGroupRequest $request, ImsLot $lot, ?ImsLotGroup $group, ImsService $service): RedirectResponse
    {
        $data = $request->validated();

        try {
            /** @var numeric-string $packs */
            $packs = (string) $data['pack_count'];
            $service->saveGroup(
                $lot,
                $group,
                $packs,
                isset($data['location_id']) ? (int) $data['location_id'] : null,
                $data['expiry_date'] ?? null,
                $data['remark'] ?? null,
            );
        } catch (ImsException $e) {
            return back()->withInput()->withErrors(['group' => $e->getMessage()]);
        }

        return redirect()->route('ims.lots.edit', $lot)->with('status', __('ims.group_saved'));
    }

    public function storePhoto(ImsLotPhotoRequest $request, ImsLot $imsLot, AttachmentUploadService $uploads): RedirectResponse
    {
        $userId = $request->user()?->id;
        abort_if($userId === null, 401);

        try {
            $uploads->upload($request->file('photo'), $imsLot, 'PHOTO', $userId);
        } catch (InfectedFileException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        return redirect()->route('ims.lots.edit', $imsLot)->with('status', __('ims.photo_uploaded'));
    }

    /** Inline image (the generic attachment download forces a save-as). Authorised per request. */
    public function photo(ImsLot $imsLot, Attachment $attachment): StreamedResponse
    {
        $this->authorize('view', $imsLot);
        abort_unless(
            $attachment->owner_type === $imsLot->getMorphClass()
            && $attachment->owner_id === $imsLot->id
            && $attachment->doc_type === 'PHOTO',
            404,
        );

        return Storage::disk('attachments')->response($attachment->stored_name, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
