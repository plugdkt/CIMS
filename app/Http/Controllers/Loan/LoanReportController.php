<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Reports\LoanDashboardService;
use App\Domain\Loan\Reports\LoanReportService;
use App\Domain\Loan\Reports\LoanReportTable;
use App\Domain\Reporting\Services\CsvInjectionGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanReportRequest;
use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * ITM-2610-051 step 5 (spec section 14): the lending dashboard, the report pages and their CSV exports.
 * Thin: access and filters in LoanReportRequest (own branch; another branch = 403), the branch rule in
 * LoanReportScope, the queries in LoanReportService / LoanDashboardService.
 *
 * Every export goes through CsvInjectionGuard (every cell, headings included) and writes an audit row
 * `LOAN_REPORT_EXPORT` (who, which report, which branch, which filters, how many rows) BEFORE the file is sent.
 */
final class LoanReportController extends Controller
{
    /** Rows shown on the page; the CSV file carries up to EXPORT_ROWS. */
    private const PAGE_ROWS = 200;

    private const EXPORT_ROWS = 20000;

    public function index(LoanReportRequest $request, LoanDashboardService $dashboard): View
    {
        /** @var User $user */
        $user = $request->user();
        $scope = $request->scope();

        return view('loans.reports.index', [
            'summary' => $dashboard->summary($scope),
            'reports' => LoanReportService::keys(),
            'isAdmin' => $scope->allBranches,
            'hasLab' => $scope->allBranches || $user->lab_id !== null,
            'labs' => $this->labs($scope->allBranches),
        ]);
    }

    public function show(LoanReportRequest $request, string $report, LoanReportService $service): View
    {
        /** @var User $user */
        $user = $request->user();
        $scope = $request->scope();

        return view('loans.reports.show', [
            'report' => $report,
            'table' => $service->build($report, $scope, $request->filter(), self::PAGE_ROWS),
            'filters' => LoanReportService::FILTERS[$report],
            'pageRows' => self::PAGE_ROWS,
            'isAdmin' => $scope->allBranches,
            'hasLab' => $scope->allBranches || $user->lab_id !== null,
            'labs' => $this->labs($scope->allBranches),
        ]);
    }

    public function export(LoanReportRequest $request, string $report, LoanReportService $service): Response
    {
        /** @var User $user */
        $user = $request->user();
        $scope = $request->scope();
        $filter = $request->filter();

        $table = $service->build($report, $scope, $filter, self::EXPORT_ROWS);

        // Audited first: if the audit row cannot be written, no file leaves.
        AuditLog::record(
            action: 'LOAN_REPORT_EXPORT',
            userId: $user->id,
            username: $user->username,
            newValue: [
                'report' => $report,
                'format' => 'csv',
                'lab' => $scope->describe(),
                'filters' => $filter->forAudit(),
                'rows' => count($table->rows),
                'truncated' => $table->truncated,
                'with_values' => $scope->canSeeValue,
            ],
        );

        return response($this->csv($table), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="loan-report-'.$report.'-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * SEC-IN-10: every cell — headings and numbers too — passes CsvInjectionGuard, so nothing a user once typed
     * (a name, a reference, an asset tag) can open as a formula. UTF-8 with a BOM so a spreadsheet reads Thai.
     */
    private function csv(LoanReportTable $table): string
    {
        $handle = fopen('php://temp', 'r+');
        abort_if($handle === false, 500);

        $clean = fn (array $cells): array => array_map(fn ($cell) => CsvInjectionGuard::sanitize((string) $cell), $cells);

        fputcsv($handle, $clean($table->headings), ',', '"', '');
        foreach ($table->rows as $row) {
            fputcsv($handle, $clean($row), ',', '"', '');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$content;
    }

    /** @return Collection<int, Lab> */
    private function labs(bool $allBranches): Collection
    {
        return $allBranches ? Lab::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']) : collect();
    }
}
