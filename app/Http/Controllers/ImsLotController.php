<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Domain\Inventory\Services\ImsService;
use App\Http\Requests\ImsTransferRequest;
use App\Models\ImsLot;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        $lots = ImsLot::query()
            ->with(['item.baseUnit', 'item.category', 'lab'])
            ->when(! $user->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $user->lab_id))
            ->when($categoryId !== null, fn ($q) => $q->whereHas('item', fn ($i) => $i->where('category_id', $categoryId)))
            ->when(! $showEmpty, fn ($q) => $q->where('qty_remaining_base', '>', 0))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('lot_no', 'like', "%{$search}%")
                        ->orWhereHas('item', fn ($i) => $i->where('item_code', 'like', "%{$search}%")
                            ->orWhere('name_th', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('ims.lots.index', [
            'lots' => $lots,
            'search' => $search,
            'showEmpty' => $showEmpty,
            'categoryId' => $categoryId,
            'categories' => ItemCategory::orderBy('id')->get(),
        ]);
    }

    public function transferForm(ImsLot $imsLot): View
    {
        $this->authorize('transfer', $imsLot);

        $imsLot->load(['item.baseUnit', 'lab']);

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
}
