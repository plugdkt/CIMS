<?php

declare(strict_types=1);

namespace App\Livewire\Items;

use App\Models\Item;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-MD-07: search over name_th/name_en/brand/item_code/cas_no.
 * Uses LIKE, not the ft_items FULLTEXT index (still in the schema, spec's DDL calls
 * for it) — MySQL/MariaDB's default FULLTEXT parser tokenizes on whitespace, and Thai
 * has none, so a whole name like "โซเดียมไฮดรอกไซด์" indexes as one token and a partial
 * query like "โซเดียม" via MATCH AGAINST would never match it. LIKE searches the actual
 * substring correctly regardless of language.
 */
#[Layout('components.layout')]
final class ItemTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Item::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $items = Item::query()
            ->with(['category', 'baseUnit'])
            ->when(trim($this->search) !== '', function ($query) {
                $term = trim($this->search);
                $query->where(function ($q) use ($term) {
                    $q->where('name_th', 'like', "%{$term}%")
                        ->orWhere('name_en', 'like', "%{$term}%")
                        ->orWhere('brand', 'like', "%{$term}%")
                        ->orWhere('item_code', 'like', "%{$term}%")
                        ->orWhere('cas_no', 'like', "%{$term}%");
                });
            })
            ->orderBy('name_th')
            ->paginate(20);

        return view('livewire.items.item-table', [
            'items' => $items,
            'canManage' => auth()->user()?->can('create', Item::class) ?? false,
        ]);
    }
}
