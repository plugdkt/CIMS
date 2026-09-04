<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\DTO\DateRangeFilter;
use App\Domain\Reporting\DTO\UsageSummaryFilter;
use App\Domain\Reporting\Exports\BelowReorderPointExport;
use App\Domain\Reporting\Exports\ControlledSubstancesExport;
use App\Domain\Reporting\Exports\DeadStockExport;
use App\Domain\Reporting\Exports\ExpiringStockExport;
use App\Domain\Reporting\Exports\StockTakeVarianceExport;
use App\Domain\Reporting\Exports\UsageSummaryExport;
use App\Domain\Reporting\Services\ControlledSubstancesPdfService;
use App\Domain\Reporting\Services\StockTakeVariancePdfService;
use App\Models\Lab;
use App\Models\StockTake;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** FR-8 / §7.8: every report not already served by an existing feature (F-01, F-03). */
final class ReportController extends Controller
{
    public function index(): View
    {
        $this->authorize('report.view');

        return view('reports.index', [
            'labs' => Lab::where('is_active', true)->orderBy('name_th')->get(),
            'stockTakes' => StockTake::orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function usageSummaryExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        $filter = new UsageSummaryFilter(
            requesterName: $request->string('requester_name')->value() ?: null,
            purposeDetail: $request->string('purpose_detail')->value() ?: null,
            faculty: $request->string('faculty')->value() ?: null,
            dateFrom: $request->string('from')->value() ?: null,
            dateTo: $request->string('to')->value() ?: null,
        );

        return Excel::download(new UsageSummaryExport($filter), 'usage-summary.xlsx');
    }

    public function expiringStockExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(new ExpiringStockExport($this->dateRangeFrom($request)), 'expiring-stock.xlsx');
    }

    public function belowReorderPointExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        $labId = $request->integer('lab_id') ?: null;

        return Excel::download(new BelowReorderPointExport($labId), 'below-reorder-point.xlsx');
    }

    public function deadStockExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        $labId = $request->integer('lab_id') ?: null;

        return Excel::download(new DeadStockExport($labId), 'dead-stock.xlsx');
    }

    public function controlledSubstancesExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(new ControlledSubstancesExport($this->dateRangeFrom($request)), 'controlled-substances.xlsx');
    }

    public function controlledSubstancesPdf(Request $request, ControlledSubstancesPdfService $service): Response
    {
        $this->authorize('report.view');

        $pdf = $service->render($this->dateRangeFrom($request));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="controlled-substances.pdf"',
        ]);
    }

    public function stockTakeVarianceExcel(StockTake $stockTake): BinaryFileResponse
    {
        $this->authorize('report.view');

        return Excel::download(new StockTakeVarianceExport($stockTake), "stock-take-{$stockTake->doc_no}.xlsx");
    }

    public function stockTakeVariancePdf(StockTake $stockTake, StockTakeVariancePdfService $service): Response
    {
        $this->authorize('report.view');

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
}
