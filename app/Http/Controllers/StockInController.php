<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\MissingDensityException;
use App\Domain\Inventory\Services\LedgerService;
use App\Domain\Labeling\Services\ContainerLabelPdfService;
use App\Domain\Shared\UnitConverter;
use App\Http\Requests\StockInRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockLedger;
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
        /** @var \App\Models\User $user */
        $user = $request->user();
        abort_unless(
            $user->can('receiving.manage')
            || $user->can('item.manage')
            || $user->can('ledger.adjust')
            || $user->hasRole('ADMIN'),
            403
        );

        $selectedItemId = $item instanceof Item ? $item->id : $request->query('item_id');
        $selectedItem = $selectedItemId ? Item::find($selectedItemId) : null;

        $locations = $user->isBranchManager()
            ? Location::where('lab_id', $user->lab_id)->orderBy('name')->get()
            : Location::orderBy('name')->get();

        return view('stock-in.create', [
            'items' => Item::where('is_active', true)->orderBy('name_th')->get(),
            'selectedItem' => $selectedItem,
            'units' => Unit::orderBy('sort_order')->get(),
            'locations' => $locations,
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

        // User-reported 2026-09-22: an item stocked in as mL was stored and displayed as L.
        // The catalog import parses a base unit out of a free-text product name ("... 1 L
        // /ขวด"), which is a guess about packaging, not about the scale people actually
        // work at — real dispensing happens in mL. The unit someone physically receives
        // stock in is the authoritative one, so the first real receipt adopts it.
        if ($item->base_unit_id === null || ! StockLedger::where('item_id', $item->id)->exists()) {
            $this->adoptBaseUnit($item, $unit);
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

    /**
     * Only ever called before the item has any ledger history — every "_base" column is
     * stored in the item's own base unit and `stock_ledger` is append-only (AGENT RULE #6),
     * so once a single row exists that unit can never be reinterpreted. `reorder_point_base`
     * is the one stored value that has to move with the unit; `package_size` does not (it is
     * expressed in `package_unit_id`, which is left alone).
     */
    private function adoptBaseUnit(Item $item, Unit $unit): void
    {
        $previousBaseUnit = $item->baseUnit()->first();

        $item->base_unit_id = $unit->id;
        $item->package_unit_id ??= $unit->id;

        /** @var numeric-string $reorderPoint */
        $reorderPoint = $item->reorder_point_base;

        if ($previousBaseUnit !== null
            && $previousBaseUnit->id !== $unit->id
            && bccomp($reorderPoint, '0', 6) > 0
        ) {
            try {
                $item->reorder_point_base = $this->converter->fromBase(
                    $this->converter->crossDimension(
                        $this->converter->toBase($reorderPoint, $previousBaseUnit),
                        $previousBaseUnit->dimension,
                        $unit->dimension,
                        $item->density_g_per_ml !== null ? (float) $item->density_g_per_ml : null,
                    ),
                    $unit,
                );
            } catch (MissingDensityException) {
                // Crossing MASS<->VOLUME without a density can't be converted. Keeping the
                // old number would silently assert a threshold nobody set (1 g becoming
                // 1 mL), so the alert is cleared instead and can be re-entered by hand.
                $item->reorder_point_base = '0.000000';
            }
        }

        $item->save();
        $item->refresh();
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
