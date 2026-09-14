<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Labeling\Services\ContainerLabelPdfService;
use App\Domain\Shared\UnitConverter;
use App\Http\Requests\StockInRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\Location;
use App\Models\Unit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockInController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledgerService,
        private readonly UnitConverter $converter,
    ) {
    }

    public function create(Request $request, ?Item $item = null): View
    {
        $selectedItemId = $item instanceof Item ? $item->id : $request->query('item_id');
        $selectedItem = $selectedItemId ? Item::find($selectedItemId) : null;

        return view('stock-in.create', [
            'items' => Item::where('is_active', true)->orderBy('name_th')->get(),
            'selectedItem' => $selectedItem,
            'units' => Unit::orderBy('sort_order')->get(),
            'locations' => Location::orderBy('name')->get(),
        ]);
    }

    public function store(StockInRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $item = Item::findOrFail((int) $data['item_id']);
        $unit = Unit::findOrFail((int) $data['unit_id']);
        $location = Location::findOrFail((int) $data['location_id']);
        $trackingType = $data['tracking_type'];
        $expiryDate = ! empty($data['expiry_date']) ? (string) $data['expiry_date'] : $item->expiry_date;
        $lotNo = $data['lot_no'] ?? null;
        $remark = $data['remark'] ?? 'เติมสต็อกคลังย่อย';
        $user = $request->user();
        abort_if($user === null, 401);
        $userId = (int) $user->id;

        if ($item->base_unit_id === null) {
            $item->base_unit_id = $unit->id;
            $item->package_unit_id ??= $unit->id;
            $item->save();
            $item->refresh();
        }

        /** @var list<int> $createdContainerIds */
        $createdContainerIds = [];

        DB::transaction(function () use (
            $trackingType,
            $data,
            $item,
            $unit,
            $location,
            $expiryDate,
            $lotNo,
            $remark,
            $userId,
            &$createdContainerIds
        ) {
            if ($trackingType === 'CONTAINER') {
                $count = (int) $data['container_count'];
                /** @var numeric-string $qtyPerCont */
                $qtyPerCont = (string) $data['qty_per_container'];
                /** @var numeric-string $qtyPerContBase */
                $qtyPerContBase = $this->converter->toItemBase($item, $unit, $qtyPerCont);

                for ($seq = 1; $seq <= $count; $seq++) {
                    $barcode = sprintf('WS-%s-%04d-%s', now()->format('ymd'), $item->id, Str::upper(Str::random(4)));
                    $container = Container::create([
                        'barcode' => $barcode,
                        'item_id' => $item->id,
                        'location_id' => $location->id,
                        'lot_no' => $lotNo,
                        'received_at' => now()->toDateString(),
                        'expiry_date' => $expiryDate,
                        'initial_qty_base' => $qtyPerContBase,
                        'remaining_qty_base' => '0.000000',
                        'status' => 'SEALED',
                    ]);

                    $this->ledgerService->receive(
                        $container->id,
                        $qtyPerContBase,
                        new LedgerEntryData(
                            displayUnitId: $unit->id,
                            createdBy: $userId,
                            refType: 'WORKING_STOCK',
                            refId: $container->id,
                            refDocNo: $barcode,
                            remark: $remark,
                        )
                    );

                    $createdContainerIds[] = $container->id;
                }
            } else {
                // BULK mode
                /** @var numeric-string $qty */
                $qty = (string) $data['qty'];
                /** @var numeric-string $qtyBase */
                $qtyBase = $this->converter->toItemBase($item, $unit, $qty);
                $barcode = sprintf('BLK-%s-%04d-%s', now()->format('ymd'), $item->id, Str::upper(Str::random(4)));

                $container = Container::create([
                    'barcode' => $barcode,
                    'item_id' => $item->id,
                    'location_id' => $location->id,
                    'lot_no' => $lotNo,
                    'received_at' => now()->toDateString(),
                    'expiry_date' => $expiryDate,
                    'initial_qty_base' => $qtyBase,
                    'remaining_qty_base' => '0.000000',
                    'status' => 'IN_USE',
                    'opened_at' => now()->toDateString(),
                ]);

                $this->ledgerService->receive(
                    $container->id,
                    $qtyBase,
                    new LedgerEntryData(
                        displayUnitId: $unit->id,
                        createdBy: $userId,
                        refType: 'WORKING_STOCK',
                        refId: $container->id,
                        refDocNo: $barcode,
                        remark: $remark,
                    )
                );

                $createdContainerIds[] = $container->id;
            }
        }, 3);

        $redirect = redirect()->route('items.show', $item)
            ->with('status', __('stock.stock_in_success'));

        if ($trackingType === 'CONTAINER' && !empty($createdContainerIds)) {
            $redirect->with('label_container_ids', implode(',', $createdContainerIds));
        }

        return $redirect;
    }

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
