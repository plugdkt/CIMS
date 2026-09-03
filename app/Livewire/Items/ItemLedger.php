<?php

declare(strict_types=1);

namespace App\Livewire\Items;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\Services\LedgerQueryService;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-LG-01..04: per-item ledger register (F-03) — header per F-03, a display-unit
 * switcher (FR-LG-03: view-only, `qty_in_base`/`qty_out_base`/`balance_base` in
 * storage never change), and filters (date range, txn type, requester, container).
 * Filters live in the URL (`#[Url]`) so the T-025 export links can carry the exact
 * same query string the screen is currently showing.
 */
#[Layout('components.layout')]
final class ItemLedger extends Component
{
    use WithPagination;

    public Item $item;

    #[Url(as: 'from')]
    public string $dateFrom = '';

    #[Url(as: 'to')]
    public string $dateTo = '';

    #[Url(as: 'type')]
    public string $txnType = '';

    #[Url(as: 'receiver')]
    public string $receiverName = '';

    #[Url(as: 'container')]
    public string $containerBarcode = '';

    #[Url(as: 'unit')]
    public ?int $displayUnitId = null;

    public function mount(Item $item): void
    {
        $this->authorize('viewAny', StockLedger::class);

        $this->item = $item;
        $this->displayUnitId ??= $item->base_unit_id;
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function updatingTxnType(): void
    {
        $this->resetPage();
    }

    public function updatingReceiverName(): void
    {
        $this->resetPage();
    }

    public function updatingContainerBarcode(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $item = $this->item->load('baseUnit');
        $queryService = app(LedgerQueryService::class);
        $filter = $this->filter();

        $paginator = $queryService->query($item, $filter)->paginate(30);

        /** @var Unit $itemBaseUnit */
        $itemBaseUnit = $item->baseUnit()->firstOrFail();
        $displayUnit = Unit::find($this->displayUnitId) ?? $itemBaseUnit;

        $rows = new LengthAwarePaginator(
            $queryService->formatRows($paginator->items(), $item, $displayUnit),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path(), 'query' => request()->query()],
        );

        return view('livewire.items.item-ledger', [
            'item' => $item,
            'rows' => $rows,
            'displayUnit' => $displayUnit,
            'availableUnits' => Unit::where('dimension', $itemBaseUnit->dimension)->orderBy('sort_order')->get(),
        ]);
    }

    public function filter(): LedgerFilter
    {
        return new LedgerFilter(
            dateFrom: $this->dateFrom !== '' ? $this->dateFrom : null,
            dateTo: $this->dateTo !== '' ? $this->dateTo : null,
            txnType: $this->txnType !== '' ? $this->txnType : null,
            receiverName: $this->receiverName !== '' ? $this->receiverName : null,
            containerBarcode: $this->containerBarcode !== '' ? $this->containerBarcode : null,
        );
    }
}
