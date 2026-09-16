<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Chemicals\Services\ChemicalSyncService;
use App\Http\Requests\ItemRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ItemController extends Controller
{
    public function create(Request $request): View
    {
        $this->authorize('create', Item::class);

        $item = new Item([
            'cas_no' => $request->query('cas_no'),
            'name_en' => $request->query('name_en'),
            'formula' => $request->query('formula'),
            'category_id' => $request->query('category_id') ? (int) $request->query('category_id') : ItemCategory::where('code', 'CHEMICAL')->value('id'),
        ]);

        return view('items.form', [
            'item' => $item,
            'categories' => ItemCategory::orderBy('name_th')->get(),
            'units' => Unit::orderBy('sort_order')->get(),
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        Item::create($request->validated());

        return redirect()->route('items.index')->with('status', __('items.saved'));
    }

    public function show(Item $item, Request $request): View
    {
        $this->authorize('view', $item);
        $this->authorize('viewAny', Container::class);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $privileged = $user->hasRole('ADMIN') || $user->hasRole('AUDITOR');
        $labId = $privileged ? null : $user->lab_id;
        // A non-privileged viewer with no lab_id has nothing to scope by — show
        // nothing, never every lab's containers (privileged is the only case that
        // legitimately means "no filter, show every lab").
        $unassigned = ! $privileged && $labId === null;

        $containers = Container::query()
            ->where('item_id', $item->id)
            ->whereIn('status', ['SEALED', 'IN_USE'])
            ->where('remaining_qty_base', '>', 0)
            ->when($unassigned, fn ($query) => $query->whereRaw('1 = 0'))
            ->when(! $unassigned && $labId !== null, fn ($query) => $query->whereHas('location', fn ($q) => $q->where('lab_id', $labId)))
            ->with('location')
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->get();

        return view('items.show', [
            'item' => $item,
            'sdsAttachments' => $item->attachments()->where('doc_type', 'SDS')->orderByDesc('version')->get(),
            'containers' => $containers,
        ]);
    }

    public function edit(Item $item): View
    {
        $this->authorize('update', $item);

        return view('items.form', [
            'item' => $item,
            'categories' => ItemCategory::orderBy('name_th')->get(),
            'units' => Unit::orderBy('sort_order')->get(),
            'sdsAttachments' => $item->attachments()->where('doc_type', 'SDS')->orderByDesc('version')->get(),
        ]);
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $item->update($request->validated());

        return redirect()->route('items.index')->with('status', __('items.saved'));
    }

    public function syncPubChem(Item $item, ChemicalSyncService $syncService): RedirectResponse
    {
        $this->authorize('update', $item);

        $synced = $syncService->syncItem($item);

        if (! $synced) {
            return redirect()->route('items.show', $item)
                ->with('status_warning', __('chemicals.sync_not_found'));
        }

        return redirect()->route('items.show', $item)
            ->with('status', __('chemicals.sync_success'));
    }
}
