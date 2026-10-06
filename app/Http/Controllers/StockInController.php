<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Labeling\Services\ContainerLabelPdfService;
use App\Models\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Since 2026-09-30 working stock is no longer created by a direct stock-in form — it only
 * comes from cutting an IMS lot off into working stock ({@see ImsLotController}). What is
 * left here is the barcode-label printing both flows share.
 */
final class StockInController extends Controller
{
    public function labels(Request $request, string $size, ContainerLabelPdfService $service): Response
    {
        abort_unless(in_array($size, ContainerLabelPdfService::availableSizes(), true), 404);

        $idsString = $request->query('ids', '');
        $ids = array_filter(array_map('intval', explode(',', (string) $idsString)));
        abort_if(empty($ids), 404, 'No containers selected');

        $containers = Container::with('item')->whereIn('id', $ids)->orderBy('id')->get();
        abort_if($containers->isEmpty(), 404);

        $pdf = $service->render($containers, $size);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="working-stock-labels-'.$size.'.pdf"',
        ]);
    }
}
