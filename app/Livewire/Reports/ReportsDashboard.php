<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\DTO\UsageSummaryFilter;
use App\Domain\Reporting\Exports\BelowReorderPointExport;
use App\Domain\Reporting\Exports\ControlledSubstancesExport;
use App\Domain\Reporting\Exports\DeadStockExport;
use App\Domain\Reporting\Exports\ExpiringStockExport;
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
 * behind a download — table + a small unit-agnostic summary chart, live-filtered
 * (no submit button), export links still point at the same unchanged download routes
 * so a filtered view can always be taken away as a real file. Every report keeps the
 * exact same query as its Export class (via that class's own `results()`, extracted
 * for this reuse) — this page never re-derives the filtering logic, only displays it.
 *
 * On-screen tables are capped (`ROW_LIMIT`) for page weight; export is uncapped.
 */
#[Layout('components.layout')]
final class ReportsDashboard extends Component
{
    private const ROW_LIMIT = 100;

    /** @var array<int, string> */
    private const TABS = [
        'usage_summary', 'expiring_stock', 'below_reorder', 'dead_stock',
        'controlled_substances', 'stock_take_variance',
    ];

    #[Url]
    public string $tab = 'usage_summary';

    #[Url]
    public ?int $labId = null;

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

    public function mount(): void
    {
        $this->authorize('report.view');

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'usage_summary';
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

        return $user->hasRole('LAB_MANAGER') ? $user->lab_id : null;
    }

    public function render(): View
    {
        $restrictedLabId = $this->restrictedLabId();
        $effectiveLabId = $restrictedLabId ?? $this->labId;

        [$rows, $total, $chart] = match ($this->tab) {
            'usage_summary' => $this->usageSummaryData($effectiveLabId),
            'expiring_stock' => $this->expiringStockData($effectiveLabId),
            'below_reorder' => $this->belowReorderData($effectiveLabId),
            'dead_stock' => $this->deadStockData($effectiveLabId),
            'controlled_substances' => $this->controlledSubstancesData($effectiveLabId),
            'stock_take_variance' => $this->stockTakeVarianceData($restrictedLabId),
            default => [collect(), 0, []],
        };

        return view('livewire.reports.reports-dashboard', [
            'rows' => $rows,
            'total' => $total,
            'chart' => $chart,
            'labs' => Lab::where('is_active', true)->orderBy('name_th')->get(),
            'stockTakes' => $restrictedLabId !== null
                ? StockTake::where('lab_id', $restrictedLabId)->orderByDesc('id')->limit(50)->get()
                : StockTake::orderByDesc('id')->limit(50)->get(),
            'restrictedLabId' => $restrictedLabId,
        ]);
    }

    /** @return array{0: Collection<int, IssueTransaction>, 1: int, 2: array<int, array{label: string, value: float}>} */
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

        // Items are measured in incompatible units (mg vs mL vs pcs), so ranking by
        // issue-transaction frequency rather than summed quantity — same reasoning
        // already used for the home dashboard's own "top items" chart.
        $chart = $results
            ->countBy(fn (IssueTransaction $row) => $row->requisitionItem?->item->name_th ?? '—')
            ->sortDesc()
            ->take(8)
            ->map(fn ($count, $label) => ['label' => (string) $label, 'value' => (float) $count])
            ->values()
            ->all();

        return [$results->take(self::ROW_LIMIT), $results->count(), $chart];
    }

    /** @return array{0: Collection<int, Container>, 1: int, 2: array<int, array{label: string, value: float}>} */
    private function expiringStockData(?int $labId): array
    {
        $filter = new DateRangeFilter(
            dateFrom: $this->expiringFrom ?: null,
            dateTo: $this->expiringTo ?: null,
        );

        $results = (new ExpiringStockExport($filter, $labId))->results();

        $chart = $results
            ->countBy(fn (Container $row) => $row->expiry_date?->format('Y-m') ?? '—')
            ->sortKeys()
            ->map(fn ($count, $label) => ['label' => (string) $label, 'value' => (float) $count])
            ->values()
            ->all();

        return [$results->take(self::ROW_LIMIT), $results->count(), $chart];
    }

    /** @return array{0: Collection<int, array{item: Item, current_balance_base: string}>, 1: int, 2: array<int, array{label: string, value: float}>} */
    private function belowReorderData(?int $labId): array
    {
        // Each item's current balance is looked up once here and carried alongside it
        // (not as a dynamic Eloquent attribute — PHPStan can't type that) so neither
        // the chart calculation below nor the table in the view re-queries stock_ledger.
        $results = (new BelowReorderPointExport($labId))->results()
            ->map(fn (Item $item) => [
                'item' => $item,
                'current_balance_base' => (string) (StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000'),
            ]);

        // A ratio (balance ÷ reorder point), not a raw quantity, so items with
        // different units stay comparable on one chart — lowest (most urgent) first.
        $chart = $results
            ->map(function (array $row) {
                $item = $row['item'];
                $percent = bccomp($item->reorder_point_base, '0', 6) === 0
                    ? 0.0
                    : round(((float) $row['current_balance_base'] / (float) $item->reorder_point_base) * 100, 1);

                return ['label' => $item->name_th, 'value' => $percent];
            })
            ->sortBy('value')
            ->take(8)
            ->values()
            ->all();

        return [$results->take(self::ROW_LIMIT), $results->count(), $chart];
    }

    /** @return array{0: Collection<int, array{container: Container, last_movement_date: string|null}>, 1: int, 2: array<int, array{label: string, value: float}>} */
    private function deadStockData(?int $labId): array
    {
        $results = (new DeadStockExport($labId))->results()
            ->map(function (Container $row) {
                /** @var string|null $lastMovement */
                $lastMovement = StockLedger::where('container_id', $row->id)->orderByDesc('txn_date')->value('txn_date');

                return ['container' => $row, 'last_movement_date' => $lastMovement];
            });

        $chart = $results
            ->countBy(fn (array $row) => $row['container']->labNameOrEmpty() ?: '—')
            ->sortDesc()
            ->take(8)
            ->map(fn ($count, $label) => ['label' => (string) $label, 'value' => (float) $count])
            ->values()
            ->all();

        return [$results->take(self::ROW_LIMIT), $results->count(), $chart];
    }

    /** @return array{0: Collection<int, StockLedger>, 1: int, 2: array<int, array{label: string, value: float}>} */
    private function controlledSubstancesData(?int $labId): array
    {
        $filter = new DateRangeFilter(
            dateFrom: $this->controlledFrom ?: null,
            dateTo: $this->controlledTo ?: null,
        );

        $results = (new ControlledSubstancesExport($filter, $labId))->results();

        $chart = $results
            ->countBy(fn (StockLedger $row) => (string) __('ledger.txn_'.strtolower($row->txn_type)))
            ->sortDesc()
            ->map(fn ($count, $label) => ['label' => (string) $label, 'value' => (float) $count])
            ->values()
            ->all();

        return [$results->take(self::ROW_LIMIT), $results->count(), $chart];
    }

    /** @return array{0: Collection<int, StockTakeLine>, 1: int, 2: array<int, array{label: string, value: float}>} */
    private function stockTakeVarianceData(?int $restrictedLabId): array
    {
        if ($this->stockTakeUlid === null) {
            return [collect(), 0, []];
        }

        $stockTake = StockTake::where('ulid', $this->stockTakeUlid)->first();

        if ($stockTake === null || ($restrictedLabId !== null && $stockTake->lab_id !== $restrictedLabId)) {
            // Reset so the view falls back to "select a round" rather than a blank
            // "no results" table — a LAB_MANAGER guessing another branch's ulid gets
            // the same prompt as picking nothing at all, not a hint that it exists.
            $this->stockTakeUlid = null;

            return [collect(), 0, []];
        }

        $results = (new StockTakeVarianceExport($stockTake))->results();

        $buckets = [
            'over' => (string) __('reports.variance_over'),
            'under' => (string) __('reports.variance_under'),
            'match' => (string) __('reports.variance_match'),
            'not_counted' => (string) __('reports.not_counted'),
        ];

        $counts = $results->countBy(function (StockTakeLine $line) {
            if ($line->counted_qty_base === null) {
                return 'not_counted';
            }

            return match (true) {
                bccomp($line->diff_base ?? '0', '0', 6) > 0 => 'over',
                bccomp($line->diff_base ?? '0', '0', 6) < 0 => 'under',
                default => 'match',
            };
        });

        $chart = collect($buckets)
            ->map(fn ($label, $key) => ['label' => (string) $label, 'value' => (float) ($counts[$key] ?? 0)])
            ->values()
            ->all();

        return [$results->take(self::ROW_LIMIT), $results->count(), $chart];
    }
}
