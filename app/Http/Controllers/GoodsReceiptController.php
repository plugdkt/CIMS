<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InvalidGoodsReceiptStateException;
use App\Domain\Inventory\Services\GoodsReceiptService;
use App\Domain\Labeling\Services\ContainerLabelPdfService;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Http\Requests\GoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Lab;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class GoodsReceiptController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', GoodsReceipt::class);

        return view('goods-receipts.create', [
            'labs' => Lab::where('is_active', true)->orderBy('name_th')->get(),
        ]);
    }

    public function store(GoodsReceiptRequest $request, DocumentNumberGenerator $generator): RedirectResponse
    {
        $userId = $request->user()?->id;
        abort_if($userId === null, 401);

        $goodsReceipt = GoodsReceipt::create(array_merge($request->validated(), [
            'doc_no' => $generator->next('GRN'),
            'status' => 'DRAFT',
            'received_by' => $userId,
        ]));

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', __('goods_receipts.created'));
    }

    public function show(GoodsReceipt $goodsReceipt): View
    {
        $this->authorize('view', $goodsReceipt);

        return view('goods-receipts.show', [
            'goodsReceipt' => $goodsReceipt->load(['lab', 'receivedBy', 'items.item', 'items.unit', 'items.location']),
            'items' => Item::where('is_active', true)->orderBy('name_th')->get(),
            'units' => Unit::orderBy('sort_order')->get(),
            'canEdit' => auth()->user()?->can('update', $goodsReceipt) ?? false,
        ]);
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $goodsReceipt->update($request->validated());

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', __('goods_receipts.saved'));
    }

    public function confirm(GoodsReceipt $goodsReceipt, GoodsReceiptService $service): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        $userId = auth()->id();
        abort_if($userId === null, 401);

        try {
            $service->confirm($goodsReceipt, (int) $userId);
        } catch (InvalidGoodsReceiptStateException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return redirect()->route('goods-receipts.show', $goodsReceipt)->with('status', __('goods_receipts.confirmed'));
    }

    /** FR-RC-04: barcode labels for every container this GRN's confirm created. */
    public function labels(GoodsReceipt $goodsReceipt, string $size, ContainerLabelPdfService $service): Response
    {
        $this->authorize('view', $goodsReceipt);
        abort_unless(in_array($size, ContainerLabelPdfService::availableSizes(), true), 404);

        $containers = $service->containersFor($goodsReceipt);
        abort_if($containers->isEmpty(), 404, __('goods_receipts.no_containers_to_label'));

        $pdf = $service->render($containers, $size);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="labels-'.$goodsReceipt->doc_no.'-'.$size.'.pdf"',
        ]);
    }

    public function cancel(GoodsReceipt $goodsReceipt, GoodsReceiptService $service): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        try {
            $service->cancel($goodsReceipt);
        } catch (InvalidGoodsReceiptStateException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('goods-receipts.index')->with('status', __('goods_receipts.cancelled'));
    }
}
