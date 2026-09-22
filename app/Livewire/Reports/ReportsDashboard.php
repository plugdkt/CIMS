<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\DTO\UsageSummaryFilter;
use App\Domain\Reporting\Exports\BelowReorderPointExport;
use App\Domain\Reporting\Exports\ControlledSubstancesExport;
use App\Domain\Reporting\Exports\DeadStockExport;
use App\Domain\Reporting\Exports\ExpiringStockExport;
use App\Domain\Reporting\Exports\ItemIssueHistoryExport;
use App\Domain\Reporting\Exports\ItemReceivingHistoryExport;
use App\Domain\Reporting\Exports\ItemReturnHistoryExport;
use App\Domain\Reporting\Exports\ItemStockSummaryExport;
use App\Domain\Reporting\Exports\StockTakeVarianceExport;
use App\Domain\Reporting\Exports\UsageSummaryExport;
use App\Models\Container;
use App\Models\Item;
use App\Models\IssueTransaction;
use App\Models\Lab;
use App\Models\StockLedger;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * FR-8 / §7.8: an on-screen version of every report the export-only page used to gate
 * behind a download — a live-filtered table (no submit button; user-requested removal
 * of the earlier summary-chart display), export links still point at the same
 * unchanged download routes so a filtered view can always be taken away as a real
 * file. Every report keeps the exact same query as its Export class (via that class's
 * own `results()`, extracted for this reuse) — this page never re-derives the
 * filtering logic, only displays it.
 *
 * On-screen tables are capped (`ROW_LIMIT`) for page weight; export is uncapped.
 */
#[Layout('components.layout')]
final class ReportsDashboard extends Component
{
    private const ROW_LIMIT = 100;

    /** @var array<int, string> */
    private const TABS = [
        'item_stock_summary', 'item_issue_history', 'usage_summary', 'expiring_stock', 'below_reorder',
        'dead_stock', 'controlled_substances', 'stock_take_variance',
    ];

    #[Url]
    public string $tab = 'item_stock_summary';

    #[Url]
    public ?int $labId = null;

    #[Url]
    public string $itemStockFrom = '';

    #[Url]
    public string $itemStockTo = '';

    #[Url]
    public string $usageRequesterName = '';

    #[Url]
    public string $usagePurposeDetail = '';

    #[Url]
    public string $usageFaculty = '';

    #[Url]
    public string $usageFrom = '';

    #[Url]
    public string $usageTo = '';

    #[Url]
    public string $expiringFrom = '';

    #[Url]
    public string $expiringTo = '';

    #[Url]
    public string $controlledFrom = '';

    #[Url]
    public string $controlledTo = '';

    #[Url]
    public ?string $stockTakeUlid = null;

    /** The chemical whose dispensing history the `item_issue_history` tab shows, by ULID. */
    #[Url]
    public ?string $historyItemUlid = null;

    #[Url]
    public string $historyItemSearch = '';

    #[Url]
    public string $historyFrom = '';

    #[Url]
    public string $historyTo = '';

    public function mount(): void
    {
        $this->authorize('report.view');

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'item_stock_summary';
        }
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    private function restrictedLabId(): ?int
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->isBranchManager() ? $user->lab_id : null;
    }

    public function render(): View
    {
        $restrictedLabId = $this->restrictedLabId();
        $effectiveLabId = $restrictedLabId ?? $this->labId;

        [$rows, $total] = match ($this->tab) {
            'item_stock_summary' => $this->itemStockSummaryData($effectiveLabId),
            'item_issue_history' => $this->itemIssueHistoryData($effectiveLabId),
            'usage_summary' => $this->usageSummaryData($effectiveLabId),
            'expiring_stock' => $this->expiringStockData($effectiveLabId),
            'below_reorder' => $this->belowReorderData($effectiveLabId),
            'dead_stock' => $this->deadStockData($effectiveLabId),
            'controlled_substances' => $this->controlledSubstancesData($effectiveLabId),
            'stock_take_variance' => $this->stockTakeVarianceData($restrictedLabId),
            default => [collect(), 0],
        };

        $historyItem = $this->tab === 'item_issue_history' ? $this->selectedHistoryItem() : null;

        return view('livewire.reports.reports-dashboard', [
            'rows' => $rows,
            'total' => $total,
            'labs' => Lab::where('is_active', true)->orderBy('name_th')->get(),
            'stockTakes' => $restrictedLabId !== null
                ? StockTake::where('lab_id', $restrictedLabId)->orderByDesc('id')->limit(50)->get()
                : StockTake::orderByDesc('id')->limit(50)->get(),
            'restrictedLabId' => $restrictedLabId,
            'historyItem' => $historyItem,
            'historyCandidates' => $this->tab === 'item_issue_history' ? $this->historyCandidates($effectiveLabId) : collect(),
            'historySummary' => $historyItem === null
                ? null
                : $this->historySummaryFor($historyItem, $effectiveLabId),
            // User-requested 2026-09-22: the receiving history (IMS requisition number)
            // appended below the dispensing table, on the same tab — not a separate page.
            'receivingRows' => $historyItem === null
                ? collect()
                : $this->receivingExportFor($historyItem, $effectiveLabId)->results()->take(self::ROW_LIMIT),
            // User-reported 2026-09-22: a real return made "issued 100, balance 200" look
            // like it exceeded the 250 received — the return that explains it wasn't shown
            // anywhere. Appended after dispensing, same as the PDF/Excel ordering.
            'returnRows' => $historyItem === null
                ? collect()
                : $this->returnExportFor($historyItem, $effectiveLabId)->results()->take(self::ROW_LIMIT),
        ]);
    }

    /** @return array{issued: string, returned: string, balance: string, unit: string} */
    private function historySummaryFor(Item $item, ?int $labId): array
    {
        $export = $this->historyExportFor($item, $labId);
        $returnExport = $this->returnExportFor($item, $labId);
        $unit = $item->baseUnit;

        return [
            'issued' => $export->totalIssued(),
            'returned' => $returnExport->totalReturned(),
            'balance' => $export->remainingBalance(),
            'unit' => $unit === null ? '' : $unit->code,
        ];
    }

    /**
     * User-reported 2026-09-22 (twice): the picker must not offer the whole chemical
     * catalog, and should draw from "คลังสารเคมีของฉัน (สต็อกคงคลัง)" — the same real,
     * in-stock inventory `LabInventoryTable` shows, branch-scoped the same way. A chemical
     * with no stock physically in this branch right now isn't offered here, even if it
     * has stock elsewhere or has been dispensed before.
     *
     * @return Collection<int, Item>
     */
    private function historyCandidates(?int $labId): Collection
    {
        $search = trim($this->historyItemSearch);

        $itemIdsInStock = Container::query()
            ->select('item_id')
            ->distinct()
            ->whereIn('status', ['SEALED', 'IN_USE'])
            ->where('remaining_qty_base', '>', 0)
            ->when($labId !== null, fn ($q) => $q->whereHas('location', fn ($l) => $l->where('lab_id', $labId)));

        return Item::query()
            ->whereIn('id', $itemIdsInStock)
            ->when($search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name_th', 'like', '%'.$search.'%')
                    ->orWhere('item_code', 'like', '%'.$search.'%'),
            ))
            ->orderBy('name_th')
            ->limit(30)
            ->get();
    }

    private function selectedHistoryItem(): ?Item
    {
        if ($this->historyItemUlid === null) {
            return null;
        }

        return Item::where('ulid', $this->historyItemUlid)->first();
    }

    private function historyExportFor(Item $item, ?int $labId): ItemIssueHistoryExport
    {
        return new ItemIssueHistoryExport(
            $item,
            new DateRangeFilter(dateFrom: $this->historyFrom ?: null, dateTo: $this->historyTo ?: null),
            $labId,
        );
    }

    private function receivingExportFor(Item $item, ?int $labId): ItemReceivingHistoryExport
    {
        return new ItemReceivingHistoryExport(
            $item,
            new DateRangeFilter(dateFrom: $this->historyFrom ?: null, dateTo: $this->historyTo ?: null),
            $labId,
        );
    }

    private function returnExportFor(Item $item, ?int $labId): ItemReturnHistoryExport
    {
        return new ItemReturnHistoryExport(
            $item,
            new DateRangeFilter(dateFrom: $this->historyFrom ?: null, dateTo: $this->historyTo ?: null),
            $labId,
        );
    }

    /** @return array{0: Collection<int, IssueTransaction>, 1: int} */
    private function itemIssueHistoryData(?int $labId): array
    {
        $item = $this->selectedHistoryItem();

        if ($item === null) {
            return [collect(), 0];
        }

        $results = $this->historyExportFor($item, $labId)->results();

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /**
     * A row is flagged "low stock" once its remaining share of (used + remaining) drops
     * to this percentage or below — e.g. 20 means "80% of what came in during the period
     * is already gone." User-requested starting point; ask before assuming it should move.
     */
    private const LOW_STOCK_REMAINING_PERCENT = 20.0;

    /** @return array{0: Collection<int, array{item: Item, used_qty_base: string, remaining_base: string, remaining_percent: float|null, low_stock: bool}>, 1: int} */
    private function itemStockSummaryData(?int $labId): array
    {
        $filter = new DateRangeFilter(
            dateFrom: $this->itemStockFrom ?: null,
            dateTo: $this->itemStockTo ?: null,
        );
        $export = new ItemStockSummaryExport($filter, $labId);

        // Each row gets its own remaining-percent and a low-stock flag, so the item
        // about to run out is marked directly where it's read.
        $results = $export->results()->map(function (Item $item) use ($export) {
            $used = $export->usedQuantity($item);
            $remaining = $export->remainingBalance($item);
            $denominator = (float) $used + (float) $remaining;
            $remainingPercent = $denominator <= 0.0 ? null : round(((float) $remaining / $denominator) * 100, 1);

            return [
                'item' => $item,
                'used_qty_base' => $used,
                'remaining_base' => $remaining,
                'remaining_percent' => $remainingPercent,
                'low_stock' => $remainingPercent !== null && $remainingPercent <= self::LOW_STOCK_REMAINING_PERCENT,
            ];
        });

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /** @return array{0: Collection<int, IssueTransaction>, 1: int} */
    private function usageSummaryData(?int $labId): array
    {
        $filter = new UsageSummaryFilter(
            requesterName: $this->usageRequesterName ?: null,
            purposeDetail: $this->usagePurposeDetail ?: null,
            faculty: $this->usageFaculty ?: null,
            dateFrom: $this->usageFrom ?: null,
            dateTo: $this->usageTo ?: null,
            labId: $labId,
        );

        $results = (new UsageSummaryExport($filter))->results();

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /** @return array{0: Collection<int, Container>, 1: int} */
    private function expiringStockData(?int $labId): array
    {
        $filter = new DateRangeFilter(
            dateFrom: $this->expiringFrom ?: null,
            dateTo: $this->expiringTo ?: null,
        );

        $results = (new ExpiringStockExport($filter, $labId))->results();

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /** @return array{0: Collection<int, array{item: Item, current_balance_base: string}>, 1: int} */
    private function belowReorderData(?int $labId): array
    {
        $results = (new BelowReorderPointExport($labId))->results()
            ->map(fn (Item $item) => [
                'item' => $item,
                'current_balance_base' => (string) (StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000'),
            ]);

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /** @return array{0: Collection<int, array{container: Container, last_movement_date: string|null}>, 1: int} */
    private function deadStockData(?int $labId): array
    {
        $results = (new DeadStockExport($labId))->results()
            ->map(function (Container $row) {
                /** @var string|null $lastMovement */
                $lastMovement = StockLedger::where('container_id', $row->id)->orderByDesc('txn_date')->value('txn_date');

                return ['container' => $row, 'last_movement_date' => $lastMovement];
            });

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /** @return array{0: Collection<int, StockLedger>, 1: int} */
    private function controlledSubstancesData(?int $labId): array
    {
        $filter = new DateRangeFilter(
            dateFrom: $this->controlledFrom ?: null,
            dateTo: $this->controlledTo ?: null,
        );

        $results = (new ControlledSubstancesExport($filter, $labId))->results();

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }

    /** @return array{0: Collection<int, StockTakeLine>, 1: int} */
    private function stockTakeVarianceData(?int $restrictedLabId): array
    {
        if ($this->stockTakeUlid === null) {
            return [collect(), 0];
        }

        $stockTake = StockTake::where('ulid', $this->stockTakeUlid)->first();

        if ($stockTake === null || ($restrictedLabId !== null && $stockTake->lab_id !== $restrictedLabId)) {
            // Reset so the view falls back to "select a round" rather than a blank
            // "no results" table — a LAB_MANAGER guessing another branch's ulid gets
            // the same prompt as picking nothing at all, not a hint that it exists.
            $this->stockTakeUlid = null;

            return [collect(), 0];
        }

        $results = (new StockTakeVarianceExport($stockTake))->results();

        return [$results->take(self::ROW_LIMIT), $results->count()];
    }
}
