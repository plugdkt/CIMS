<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\DTO\UsageSummaryFilter;
use App\Domain\Reporting\Exports\BelowReorderPointExport;
use App\Domain\Reporting\Exports\ControlledSubstancesExport;
use App\Domain\Reporting\Exports\DeadStockExport;
use App\Domain\Reporting\Exports\ExpiringStockExport;
use App\Domain\Reporting\Exports\ItemIssueHistoryExport;
use App\Domain\Reporting\Exports\ItemReceivingHistoryExport;
use App\Domain\Reporting\Exports\ItemStockCardExport;
use App\Domain\Reporting\Exports\ItemStockSummaryExport;
use App\Domain\Reporting\Exports\StockTakeVarianceExport;
use App\Domain\Reporting\Exports\UsageSummaryExport;
use App\Domain\Reporting\Services\ControlledSubstancesPdfService;
use App\Domain\Reporting\Services\ItemIssueHistoryPdfService;
use App\Domain\Reporting\Services\StockTakeVariancePdfService;
use App\Models\Item;
use App\Models\StockTake;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FR-8 / §7.8: every report not already served by an existing feature (F-01, F-03).
 * A LAB_MANAGER's own branch (`users.lab_id`) always wins over whatever `lab_id` the
 * request carries — {@see labIdFor()} — so their view can't be widened via the query
 * string, matching the read-scoping decision recorded in CLAUDE.md for this feature.
 */
final class ReportController extends Controller
{
    public function usageSummaryExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        $filter = new UsageSummaryFilter(
            requesterName: $request->string('requester_name')->value() ?: null,
            purposeDetail: $request->string('purpose_detail')->value() ?: null,
            faculty: $request->string('faculty')->value() ?: null,
            dateFrom: $request->string('from')->value() ?: null,
            dateTo: $request->string('to')->value() ?: null,
            labId: $this->labIdFor($request),
        );

        return Excel::download(new UsageSummaryExport($filter), 'usage-summary.xlsx');
    }

    public function itemStockSummaryExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(
            new ItemStockSummaryExport($this->dateRangeFrom($request), $this->labIdFor($request)),
            'item-stock-summary.xlsx',
        );
    }

    /**
     * User-requested 2026-09-22: the item's dispensing history, plus its receiving history
     * (IMS requisition number) appended as a second sheet — "แนบท้ายรายงาน".
     */
    public function itemIssueHistoryExcel(Request $request, Item $item): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(
            new ItemStockCardExport($item, $this->dateRangeFrom($request), $this->labIdFor($request)),
            'item-issue-history-'.$item->item_code.'.xlsx',
        );
    }

    /** User-requested 2026-09-22: printed in the same form style as F-03 (see ItemIssueHistoryPdfService). */
    public function itemIssueHistoryPdf(Request $request, Item $item, ItemIssueHistoryPdfService $service): Response
    {
        $this->authorize('report.view');

        $range = $this->dateRangeFrom($request);
        $labId = $this->labIdFor($request);
        $issueExport = new ItemIssueHistoryExport($item, $range, $labId);
        $receivingExport = new ItemReceivingHistoryExport($item, $range, $labId);
        $pdf = $service->render($issueExport, $receivingExport);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="item-issue-history-'.$item->item_code.'.pdf"',
        ]);
    }

    public function expiringStockExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(
            new ExpiringStockExport($this->dateRangeFrom($request), $this->labIdFor($request)),
            'expiring-stock.xlsx',
        );
    }

    public function belowReorderPointExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(new BelowReorderPointExport($this->labIdFor($request)), 'below-reorder-point.xlsx');
    }

    public function deadStockExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(new DeadStockExport($this->labIdFor($request)), 'dead-stock.xlsx');
    }

    public function controlledSubstancesExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(
            new ControlledSubstancesExport($this->dateRangeFrom($request), $this->labIdFor($request)),
            'controlled-substances.xlsx',
        );
    }

    public function controlledSubstancesPdf(Request $request, ControlledSubstancesPdfService $service): Response
    {
        $this->authorize('report.view');

        $pdf = $service->render($this->dateRangeFrom($request), $this->labIdFor($request));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="controlled-substances.pdf"',
        ]);
    }

    public function stockTakeVarianceExcel(StockTake $stockTake): BinaryFileResponse
    {
        $this->authorize('report.view');
        $this->authorizeStockTakeOwnLab($stockTake);

        return Excel::download(new StockTakeVarianceExport($stockTake), "stock-take-{$stockTake->doc_no}.xlsx");
    }

    public function stockTakeVariancePdf(StockTake $stockTake, StockTakeVariancePdfService $service): Response
    {
        $this->authorize('report.view');
        $this->authorizeStockTakeOwnLab($stockTake);

        $pdf = $service->render($stockTake);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"stock-take-{$stockTake->doc_no}.pdf\"",
        ]);
    }

    private function dateRangeFrom(Request $request): DateRangeFilter
    {
        return new DateRangeFilter(
            dateFrom: $request->string('from')->value() ?: null,
            dateTo: $request->string('to')->value() ?: null,
        );
    }

    /** A branch manager's (LAB_MANAGER/AUDITOR) own `lab_id` always overrides the request's — never widened via the query string. */
    private function labIdFor(Request $request): ?int
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isBranchManager()) {
            return $user->lab_id;
        }

        return $request->integer('lab_id') ?: null;
    }

    /** A branch manager (LAB_MANAGER/AUDITOR) may only view a stock take taken in their own branch. */
    private function authorizeStockTakeOwnLab(StockTake $stockTake): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_if($user->isBranchManager() && $stockTake->lab_id !== $user->lab_id, 403);
    }
}
