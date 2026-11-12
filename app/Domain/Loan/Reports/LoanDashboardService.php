<?php

declare(strict_types=1);

namespace App\Domain\Loan\Reports;

use App\Models\Loan;
use App\Models\LoanIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 step 5 (spec section 14): the figures of the lending dashboard. A class of its own — the stock
 * DashboardService is not touched. Read-only, and narrowed by the same LoanReportScope as the reports (own branch;
 * no branch = zeros; ADMIN = every branch or the one picked). Counts and item names only: no person, no value.
 */
final class LoanDashboardService
{
    private const TOP_ITEMS = 10;

    private const TOP_MONTHS = 3;

    private const CHART_MONTHS = 12;

    /**
     * @return array{
     *     pending: int, out: int, overdue: int, incidents: int,
     *     top: list<array{item_code: string, name: string, loans: int, qty: string}>,
     *     monthly: list<array{month: string, count: int}>,
     *     monthly_max: int
     * }
     */
    public function summary(LoanReportScope $scope): array
    {
        $labId = $scope->labFilter();
        $today = CarbonImmutable::today();

        $loans = fn () => Loan::query()->when($labId !== null, fn ($q) => $q->where('lab_id', $labId));

        // Waiting for a decision: the advisor's or the branch's.
        $pending = $loans()->whereIn('status', Loan::AWAITING_MANAGER)->count();
        $out = $loans()->whereIn('status', Loan::OUT)->count();
        $overdue = $loans()->overdue()->count();

        // Like the incident pages: never the cases of a loan the viewer borrowed or typed in.
        $incidents = LoanIncident::query()
            ->where('resolution', 'PENDING')
            ->whereHas('loan', function ($q) use ($labId, $scope) {
                $q->where('borrower_id', '!=', $scope->viewerId)->where('created_by', '!=', $scope->viewerId);
                if ($labId !== null) {
                    $q->where('lab_id', $labId);
                }
            })
            ->count();

        $topRows = DB::table('loan_movements as m')
            ->join('loan_assets as a', 'a.id', '=', 'm.loan_asset_id')
            ->join('items as i', 'i.id', '=', 'a.item_id')
            ->where('m.movement_type', 'HANDOUT')
            ->where('m.created_at', '>=', $today->subMonths(self::TOP_MONTHS))
            ->when($labId !== null, fn ($q) => $q->where('m.lab_id', $labId))
            ->groupBy('i.id', 'i.item_code', 'i.name_th')
            ->orderByDesc('loans')
            ->orderByDesc('qty')
            ->orderBy('i.id')
            ->limit(self::TOP_ITEMS)
            ->selectRaw('i.item_code, i.name_th, COUNT(DISTINCT m.loan_id) AS loans, SUM(m.qty) AS qty')
            ->get();

        $top = [];
        foreach ($topRows as $row) {
            $qty = (string) $row->qty;
            $top[] = [
                'item_code' => (string) $row->item_code,
                'name' => (string) $row->name_th,
                'loans' => (int) $row->loans,
                'qty' => str_contains($qty, '.') ? (rtrim(rtrim($qty, '0'), '.') ?: '0') : $qty,
            ];
        }

        // Loans handed out per month, the last 12 months including this one (a month with none shows 0).
        $firstMonth = $today->startOfMonth()->subMonths(self::CHART_MONTHS - 1);
        $perMonth = DB::table('loans as l')
            ->whereNotNull('l.handed_out_at')
            ->where('l.handed_out_at', '>=', $firstMonth)
            ->when($labId !== null, fn ($q) => $q->where('l.lab_id', $labId))
            ->groupByRaw("DATE_FORMAT(l.handed_out_at, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(l.handed_out_at, '%Y-%m') AS ym, COUNT(*) AS c")
            ->pluck('c', 'ym')
            ->all();

        $monthly = [];
        $max = 0;
        for ($n = 0; $n < self::CHART_MONTHS; $n++) {
            $month = $firstMonth->addMonths($n);
            $count = (int) ($perMonth[$month->format('Y-m')] ?? 0);
            $max = max($max, $count);
            $monthly[] = ['month' => $month->format('m/Y'), 'count' => $count];
        }

        return [
            'pending' => $pending,
            'out' => $out,
            'overdue' => $overdue,
            'incidents' => $incidents,
            'top' => $top,
            'monthly' => $monthly,
            'monthly_max' => $max,
        ];
    }
}
