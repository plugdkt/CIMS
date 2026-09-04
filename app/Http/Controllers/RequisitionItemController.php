<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\RequisitionService;
use App\Http\Requests\RequisitionItemRequest;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;

final class RequisitionItemController extends Controller
{
    public function store(RequisitionItemRequest $request, Requisition $requisition, RequisitionService $service): RedirectResponse
    {
        $data = $request->validated();
        $item = Item::whereKey($data['item_id'])->firstOrFail();
        $unit = Unit::whereKey($data['unit_id'])->firstOrFail();

        /** @var numeric-string $qtyRequested */
        $qtyRequested = (string) $data['qty_requested'];

        try {
            $service->addLine($requisition, $item, $unit, $qtyRequested, [
                'reference_doc' => $data['reference_doc'] ?? null,
                'remark' => $data['remark'] ?? null,
            ]);
        } catch (InvalidRequisitionTransitionException $e) {
            return back()->withErrors(['line' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.line_added'));
    }

    public function destroy(Requisition $requisition, RequisitionItem $requisitionItem): RedirectResponse
    {
        $this->authorize('update', $requisition);

        if ($requisition->status !== 'DRAFT') {
            return back()->withErrors(['line' => __('requisitions.validation.not_draft')]);
        }

        $requisitionItem->delete();

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.line_removed'));
    }
}
