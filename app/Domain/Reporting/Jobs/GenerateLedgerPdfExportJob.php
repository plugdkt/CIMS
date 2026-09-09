<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Jobs;

use App\Domain\Inventory\DTO\LedgerFilter;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\Reporting\Services\Fr03PdfService;
use App\Models\LedgerExportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * NFR-02: "รายงาน ledger 100,000 แถว → PDF < 15s (ผ่าน Queue + แจ้งเมื่อเสร็จ)" —
 * `LedgerExportController::pdf()` dispatches this instead of rendering inline once a
 * filtered ledger exceeds `ASYNC_ROW_THRESHOLD`. Renders the exact same
 * `Fr03PdfService` output a synchronous export would, just off the request cycle.
 */
final class GenerateLedgerPdfExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $exportRequestId)
    {
    }

    public function handle(Fr03PdfService $service, NotificationService $notifications): void
    {
        // Measured empirically during T-052: a real 100,000-row render peaks around
        // 761MB even with Fr03PdfService's Cell()-based (non-HTML) renderer — raised
        // only here since this runs off the web request entirely; a real deployment
        // would size the queue worker's php.ini the same way. See CLAUDE.md.
        ini_set('memory_limit', '1536M');

        $export = LedgerExportRequest::with(['item', 'requestedBy', 'displayUnit'])->findOrFail($this->exportRequestId);

        // Eager-loaded above and guaranteed non-null by the table's own NOT NULL FKs —
        // narrowed explicitly since PHPStan can't infer that through a BelongsTo relation.
        $item = $export->item;
        $requestedBy = $export->requestedBy;
        $displayUnit = $export->displayUnit;
        if ($item === null || $requestedBy === null || $displayUnit === null) {
            throw new RuntimeException("LedgerExportRequest #{$export->id} is missing a required relation.");
        }

        $filterData = $export->filter;
        $filter = new LedgerFilter(
            dateFrom: $filterData['dateFrom'] ?? null,
            dateTo: $filterData['dateTo'] ?? null,
            txnType: $filterData['txnType'] ?? null,
            receiverName: $filterData['receiverName'] ?? null,
            containerBarcode: $filterData['containerBarcode'] ?? null,
        );

        try {
            $pdf = $service->render($item, $filter, $displayUnit);

            $path = $export->ulid.'.pdf';
            Storage::disk('ledger_exports')->put($path, $pdf);

            $export->update(['status' => 'READY', 'file_path' => $path]);

            $notifications->notifyInAppAndEmail(
                $requestedBy,
                'ledger.export_ready',
                __('notifications.ledger_export_ready_title', ['item' => $item->name_th]),
                __('notifications.ledger_export_ready_body', ['item' => $item->name_th]),
                route('ledger-exports.show', $export),
            );
        } catch (Throwable $e) {
            Log::error('GenerateLedgerPdfExportJob failed', ['export_id' => $export->id, 'exception' => $e]);

            $export->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);

            $notifications->notifyInAppAndEmail(
                $requestedBy,
                'ledger.export_failed',
                __('notifications.ledger_export_failed_title', ['item' => $item->name_th]),
                __('notifications.ledger_export_failed_body'),
                route('ledger-exports.show', $export),
            );
        }
    }
}
