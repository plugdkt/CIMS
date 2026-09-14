<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Inventory\Services\LedgerQueryService;
use App\Domain\Reporting\Exports\Fr03Export;
use App\Domain\Reporting\Jobs\GenerateLedgerPdfExportJob;
use App\Domain\Reporting\Services\Fr03PdfService;
use App\Models\Item;
use App\Models\LedgerExportRequest;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FR-LG-05: PDF/Excel twins of the on-screen ledger (T-024) — query params match
 * `ItemLedger`'s `#[Url]` aliases exactly, so an export link built from the current
 * page URL always exports precisely what's on screen.
 */
final class LedgerExportController extends Controller
{
    /**
     * NFR-02: beyond this many matching rows, the PDF is generated in the background
     * instead of inline — see CLAUDE.md for how this number was picked.
     */
    private const ASYNC_ROW_THRESHOLD = 5000;

    public function pdf(Request $request, Item $item, Fr03PdfService $service, LedgerQueryService $ledgerQuery): Response|RedirectResponse
    {
        $this->authorize('viewAny', StockLedger::class);

        $userId = $request->user()?->id;
        abort_if($userId === null, 401);

        $filter = $this->filterFrom($request);
        $displayUnit = $this->displayUnitFrom($request, $item);

        if ($ledgerQuery->query($item, $filter)->count() > self::ASYNC_ROW_THRESHOLD) {
            $export = LedgerExportRequest::create([
                'item_id' => $item->id,
                'requested_by' => $userId,
                'display_unit_id' => $displayUnit->id,
                'filter' => [
                    'dateFrom' => $filter->dateFrom,
                    'dateTo' => $filter->dateTo,
                    'txnType' => $filter->txnType,
                    'receiverName' => $filter->receiverName,
                    'containerBarcode' => $filter->containerBarcode,
                    'labId' => $filter->labId,
                ],
            ]);

            GenerateLedgerPdfExportJob::dispatch($export->id);

            return redirect()->route('ledger-exports.show', $export);
        }

        $pdf = $service->render($item, $filter, $displayUnit);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="F03-'.$item->item_code.'.pdf"',
        ]);
    }

    public function excel(Request $request, Item $item): BinaryFileResponse
    {
        $this->authorize('viewAny', StockLedger::class);

        $export = new Fr03Export($item, $this->filterFrom($request), $this->displayUnitFrom($request, $item));

        return Excel::download($export, "F03-{$item->item_code}.xlsx");
    }

    /**
     * NFR-02's "แจ้งเมื่อเสร็จ" link target: shows processing/failed state, or streams
     * the finished PDF once READY. Authorization is ownership-only (ST-04/IDOR) —
     * `viewAny(StockLedger)` was already satisfied back when the export was requested.
     */
    public function showExport(LedgerExportRequest $ledgerExportRequest): View|StreamedResponse
    {
        Gate::authorize('view', $ledgerExportRequest);

        $filePath = $ledgerExportRequest->file_path;
        $item = $ledgerExportRequest->item;

        if ($ledgerExportRequest->status === 'READY' && $filePath !== null && $item !== null) {
            return Storage::disk('ledger_exports')->download($filePath, 'F03-'.$item->item_code.'.pdf');
        }

        return view('ledger.export-status', ['export' => $ledgerExportRequest]);
    }

    private function filterFrom(Request $request): LedgerFilter
    {
        /** @var User $user */
        $user = $request->user();

        return new LedgerFilter(
            dateFrom: $request->string('from')->value() ?: null,
            dateTo: $request->string('to')->value() ?: null,
            txnType: $request->string('type')->value() ?: null,
            receiverName: $request->string('receiver')->value() ?: null,
            containerBarcode: $request->string('container')->value() ?: null,
            labId: $user->hasRole('LAB_MANAGER') ? $user->lab_id : null,
        );
    }

    private function displayUnitFrom(Request $request, Item $item): Unit
    {
        $unitId = $request->integer('unit') ?: $item->base_unit_id;

        return Unit::find($unitId) ?? $item->baseUnit()->firstOrFail();
    }
}
