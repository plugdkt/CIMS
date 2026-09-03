<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Reporting\Exports\Fr03Export;
use App\Domain\Reporting\Services\Fr03PdfService;
use App\Models\Item;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FR-LG-05: PDF/Excel twins of the on-screen ledger (T-024) — query params match
 * `ItemLedger`'s `#[Url]` aliases exactly, so an export link built from the current
 * page URL always exports precisely what's on screen.
 */
final class LedgerExportController extends Controller
{
    public function pdf(Request $request, Item $item, Fr03PdfService $service): Response
    {
        $this->authorize('viewAny', StockLedger::class);

        $pdf = $service->render($item, $this->filterFrom($request), $this->displayUnitFrom($request, $item));

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

    private function filterFrom(Request $request): LedgerFilter
    {
        return new LedgerFilter(
            dateFrom: $request->string('from')->value() ?: null,
            dateTo: $request->string('to')->value() ?: null,
            txnType: $request->string('type')->value() ?: null,
            receiverName: $request->string('receiver')->value() ?: null,
            containerBarcode: $request->string('container')->value() ?: null,
        );
    }

    private function displayUnitFrom(Request $request, Item $item): Unit
    {
        $unitId = $request->integer('unit') ?: $item->base_unit_id;

        return Unit::find($unitId) ?? $item->baseUnit()->firstOrFail();
    }
}
