<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\RequisitionService;
use App\Domain\Requisition\Services\ShopAvailability;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Domain\Shared\UnitConverter;
use App\Http\Requests\ShopCartAddRequest;
use App\Http\Requests\ShopCartUpdateRequest;
use App\Http\Requests\ShopCheckoutRequest;
use App\Http\Requests\ShopIndexRequest;
use App\Http\Requests\ShopShowRequest;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Shopping" way to request materials: browse what the branch can hand out (working stock plus
 * its IMS store), put items in a cart, and check out into an ordinary requisition that goes
 * through the usual approval and dispensing. The cart lives in the session — it is only a
 * wish list until checkout, nothing is reserved.
 *
 * Session shape: shop.cart = [item ulid => ['qty' => string, 'unit_id' => int]]
 */
final class ShopController extends Controller
{
    private const CART = 'shop.cart';

    public function index(ShopIndexRequest $request, ShopAvailability $availability): View|RedirectResponse
    {
        if ($gate = $this->gate()) {
            return $gate;
        }

        /** @var User $user */
        $user = $request->user();
        $labId = (int) $user->lab_id;
        $search = trim((string) $request->query('search', ''));
        $categoryId = $request->filled('category') ? (int) $request->query('category') : null;

        $matchesSearch = function ($q) use ($search) {
            $q->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('name_th', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%");
                });
            });
        };

        // How many shelf items each type tab holds (for the current search), so empty types don't clutter the tab bar.
        $counts = $availability->shelf($labId)->tap($matchesSearch)
            ->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id');

        $items = $availability->shelf($labId)
            ->with(['baseUnit', 'category'])
            ->when($categoryId !== null, fn ($q) => $q->where('category_id', $categoryId))
            ->tap($matchesSearch)
            ->orderBy('name_th')
            ->paginate(24)
            ->withQueryString();

        /** @var list<int> $ids */
        $ids = $items->pluck('id')->all();
        $photos = [];
        foreach (\App\Models\ImsLot::with('photos')->whereIn('item_id', $ids)->where('lab_id', $labId)->orderByDesc('id')->get() as $lot) {
            if (! isset($photos[$lot->item_id]) && ($photo = $lot->photos->first()) !== null) {
                $photos[$lot->item_id] = route('ims.lots.photos.show', [$lot, $photo]);
            }
        }

        return view('shop.index', [
            'items' => $items,
            'stock' => $availability->forItems($ids, $labId),
            'photos' => $photos,
            'units' => Unit::orderBy('sort_order')->get()->groupBy('dimension'),
            'categories' => ItemCategory::orderBy('id')->get(),
            'counts' => $counts,
            'search' => $search,
            'categoryId' => $categoryId,
            'cartCount' => count($this->cart()),
        ]);
    }

    /** Product page: photos, what the item is, how much the branch can hand out and where each bit sits and when it expires. */
    public function show(ShopShowRequest $request, Item $item, ShopAvailability $availability): View|RedirectResponse
    {
        if ($gate = $this->gate()) {
            return $gate;
        }

        /** @var User $user */
        $user = $request->user();
        $labId = (int) $user->lab_id;
        $stock = $availability->forItem($item, $labId);
        abort_unless($item->is_active && bccomp($stock['total'], '0', 6) > 0, 404);

        $item->load(['baseUnit', 'category']);

        $lots = \App\Models\ImsLot::with(['photos', 'location', 'groups.location'])
            ->where('item_id', $item->id)->where('lab_id', $labId)
            ->where('qty_remaining_base', '>', 0)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('id')
            ->get();

        $photos = [];
        foreach ($lots as $lot) {
            foreach ($lot->photos as $photo) {
                $photos[] = route('ims.lots.photos.show', [$lot, $photo]);
            }
        }

        // Working-stock bottles grouped by place and expiry date.
        $bottles = \App\Models\Container::with('location')
            ->where('item_id', $item->id)
            ->whereIn('status', ['SEALED', 'IN_USE'])
            ->where('remaining_qty_base', '>', 0)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->whereHas('location', fn ($q) => $q->where('lab_id', $labId))
            ->get()
            ->groupBy(fn ($c) => $c->location_id.'|'.($c->expiry_date?->toDateString() ?? ''))
            ->map(fn ($group) => [
                'location' => $group->first()?->location?->name,
                'expiry' => $group->first()?->expiry_date,
                'count' => $group->count(),
                'qty' => $group->reduce(fn (string $carry, $c) => bcadd($carry, $c->remaining_qty_base, 6), '0'),
            ])
            ->sortBy(fn ($row) => $row['expiry']->timestamp ?? PHP_INT_MAX)
            ->values();

        return view('shop.show', [
            'item' => $item,
            'stock' => $stock,
            'photos' => $photos,
            'lots' => $lots,
            'bottles' => $bottles,
            'options' => Unit::where('dimension', $item->baseUnit?->dimension)->orderBy('sort_order')->get(),
            'cartCount' => count($this->cart()),
        ]);
    }

    public function add(ShopCartAddRequest $request, ShopAvailability $availability, UnitConverter $converter): RedirectResponse
    {
        if ($gate = $this->gate()) {
            return $gate;
        }

        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $item = Item::where('ulid', $data['item'])->firstOrFail();
        $unit = Unit::findOrFail((int) $data['unit_id']);
        $baseUnit = $item->baseUnit;

        if ($baseUnit === null || $unit->dimension !== $baseUnit->dimension) {
            return back()->withErrors(['cart' => __('shop.error.unit_mismatch')]);
        }

        $cart = $this->cart();
        $key = $item->ulid;
        $qty = (string) $data['qty'];

        // Adding the same item again merges into the existing line (converted to its unit).
        if (isset($cart[$key])) {
            $existingUnit = Unit::find($cart[$key]['unit_id']);
            if ($existingUnit !== null) {
                /** @var numeric-string $added */
                $added = $qty;
                $ratio = bcdiv((string) $unit->factor_to_base, (string) $existingUnit->factor_to_base, 12);
                $qty = bcadd($cart[$key]['qty'], bcmul($added, $ratio, 6), 6);
                $unit = $existingUnit;
            }
        }

        /** @var numeric-string $qty */
        if (! $this->withinStock($item, $unit, $qty, $availability, $converter, (int) $user->lab_id)) {
            return back()->withErrors(['cart' => __('shop.error.over_stock', ['item' => $item->name_th])]);
        }

        $cart[$key] = ['qty' => $this->trim($qty), 'unit_id' => $unit->id];
        session([self::CART => $cart]);

        return back()->with('status', __('shop.added', ['item' => $item->name_th]));
    }

    public function cartPage(ShopAvailability $availability): View|RedirectResponse
    {
        if ($gate = $this->gate()) {
            return $gate;
        }

        /** @var User $user */
        $user = auth()->user();
        $lines = $this->cartLines((int) $user->lab_id, $availability);

        return view('shop.cart', [
            'lines' => $lines,
            'cartCount' => count($lines),
        ]);
    }

    public function update(ShopCartUpdateRequest $request, string $item, ShopAvailability $availability, UnitConverter $converter): RedirectResponse
    {
        if ($gate = $this->gate()) {
            return $gate;
        }

        /** @var User $user */
        $user = $request->user();
        $cart = $this->cart();
        abort_unless(isset($cart[$item]), 404);

        $model = Item::where('ulid', $item)->firstOrFail();
        $unit = Unit::findOrFail($cart[$item]['unit_id']);
        /** @var numeric-string $qty */
        $qty = (string) $request->validated('qty');

        if (! $this->withinStock($model, $unit, $qty, $availability, $converter, (int) $user->lab_id)) {
            return back()->withErrors(['cart' => __('shop.error.over_stock', ['item' => $model->name_th])]);
        }

        $cart[$item]['qty'] = $this->trim($qty);
        session([self::CART => $cart]);

        return redirect()->route('shop.cart')->with('status', __('shop.updated'));
    }

    public function remove(string $item): RedirectResponse
    {
        Gate::authorize('create', Requisition::class);

        $cart = $this->cart();
        unset($cart[$item]);
        session([self::CART => $cart]);

        return redirect()->route('shop.cart')->with('status', __('shop.removed'));
    }

    /** Cart -> an ordinary requisition (header from the checkout form, snapshot of the requester, one line per cart row), submitted straight away. */
    public function checkout(
        ShopCheckoutRequest $request,
        ShopAvailability $availability,
        UnitConverter $converter,
        RequisitionService $service,
        DocumentNumberGenerator $generator,
    ): RedirectResponse {
        if ($gate = $this->gate()) {
            return $gate;
        }

        /** @var User $user */
        $user = $request->user();
        $cart = $this->cart();
        if ($cart === []) {
            return redirect()->route('shop.index')->withErrors(['cart' => __('shop.error.empty_cart')]);
        }

        // Re-check every line against what is really there right now.
        $items = Item::whereIn('ulid', array_keys($cart))->get()->keyBy('ulid');
        foreach ($cart as $ulid => $row) {
            $item = $items->get($ulid);
            $unit = Unit::find($row['unit_id']);
            /** @var numeric-string $qty */
            $qty = $row['qty'];
            if ($item === null || $unit === null || ! $this->withinStock($item, $unit, $qty, $availability, $converter, (int) $user->lab_id)) {
                return redirect()->route('shop.cart')->withErrors(['cart' => __('shop.error.over_stock', ['item' => $item->name_th ?? $ulid])]);
            }
        }

        try {
            $requisition = DB::transaction(function () use ($request, $user, $cart, $items, $service, $generator) {
                $requisition = Requisition::create(array_merge($request->validated(), [
                    'doc_no' => $generator->next('REQ'),
                    'doc_date' => now()->toDateString(),
                    'lab_id' => $user->lab_id,
                    'requester_id' => $user->id,
                    'requester_status' => $user->person_type,
                    'requester_phone' => $user->phone_encrypted,
                    'student_code' => $user->person_type === 'STUDENT' ? $user->person_code_encrypted : null,
                    'program' => $user->program,
                    'faculty' => $user->faculty,
                    'advisor_id' => $user->advisor_id,
                    'status' => 'DRAFT',
                ]));

                foreach ($cart as $ulid => $row) {
                    /** @var numeric-string $qty */
                    $qty = $row['qty'];
                    $service->addLine($requisition, $items->get($ulid) ?? throw new \RuntimeException('item vanished'), Unit::findOrFail($row['unit_id']), $qty);
                }

                return $service->submit($requisition);
            });
        } catch (InvalidRequisitionTransitionException $e) {
            return redirect()->route('shop.cart')->withErrors(['cart' => $e->getMessage()]);
        }

        session()->forget(self::CART);

        return redirect()->route('requisitions.show', $requisition)->with('status', __('shop.checked_out'));
    }

    /** Same gates as starting any requisition: profile completed, assigned to a branch. */
    private function gate(): ?RedirectResponse
    {
        Gate::authorize('create', Requisition::class);

        /** @var User $user */
        $user = auth()->user();
        if ($user->profile_completed_at === null) {
            return redirect()->route('account.complete-profile')->with('status', __('requisitions.complete_profile_first'));
        }
        if ($user->lab_id === null) {
            return redirect()->route('account.pending-lab');
        }

        return null;
    }

    /** @return array<string, array{qty: numeric-string, unit_id: int}> */
    private function cart(): array
    {
        /** @var array<string, array{qty: numeric-string, unit_id: int}> $cart */
        $cart = session(self::CART, []);

        return $cart;
    }

    /**
     * @param  numeric-string  $qty
     */
    private function withinStock(Item $item, Unit $unit, string $qty, ShopAvailability $availability, UnitConverter $converter, int $labId): bool
    {
        $wantedBase = $converter->toItemBase($item, $unit, $qty);

        return bccomp($wantedBase, $availability->forItem($item, $labId)['total'], 6) <= 0 && bccomp($wantedBase, '0', 6) > 0;
    }

    /** @param  numeric-string  $qty */
    private function trim(string $qty): string
    {
        return str_contains($qty, '.') ? (rtrim(rtrim($qty, '0'), '.') ?: '0') : $qty;
    }

    /**
     * @return list<array{item: Item, unit: Unit, qty: string, stock: array{working: numeric-string, ims: numeric-string, total: numeric-string}}>
     */
    private function cartLines(int $labId, ShopAvailability $availability): array
    {
        $cart = $this->cart();
        if ($cart === []) {
            return [];
        }

        $items = Item::with('baseUnit')->whereIn('ulid', array_keys($cart))->get()->keyBy('ulid');
        /** @var list<int> $itemIds */
        $itemIds = $items->pluck('id')->values()->all();
        $stock = $availability->forItems($itemIds, $labId);
        $units = Unit::whereIn('id', array_column($cart, 'unit_id'))->get()->keyBy('id');

        $lines = [];
        foreach ($cart as $ulid => $row) {
            $item = $items->get($ulid);
            $unit = $units->get($row['unit_id']);
            if ($item !== null && $unit !== null) {
                $lines[] = ['item' => $item, 'unit' => $unit, 'qty' => $row['qty'], 'stock' => $stock[$item->id]];
            }
        }

        return $lines;
    }
}
