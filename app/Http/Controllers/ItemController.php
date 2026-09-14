<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ItemRequest;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class ItemController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Item::class);

        return view('items.form', [
            'item' => new Item(),
            'categories' => ItemCategory::orderBy('name_th')->get(),
            'units' => Unit::orderBy('sort_order')->get(),
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        Item::create($request->validated());

        return redirect()->route('items.index')->with('status', __('items.saved'));
    }

    public function show(Item $item): View
    {
        $this->authorize('view', $item);

        return view('items.show', [
            'item' => $item,
            'sdsAttachments' => $item->attachments()->where('doc_type', 'SDS')->orderByDesc('version')->get(),
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
}
