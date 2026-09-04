<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Requisition\Exceptions\InvalidReturnException;
use App\Domain\Requisition\Services\ReturnService;
use App\Http\Requests\RequisitionReturnRequest;
use App\Models\Container;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/** FR-ST-01 / BR-05: returning unused material back into the container it was issued from. */
final class RequisitionReturnController extends Controller
{
    public function store(
        RequisitionReturnRequest $request,
        Requisition $requisition,
        RequisitionItem $requisitionItem,
        ReturnService $service,
    ): RedirectResponse {
        $data = $request->validated();
        $container = Container::whereKey($data['container_id'])->firstOrFail();
        $unit = Unit::whereKey($data['unit_id'])->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();

        /** @var numeric-string $qtyReturned */
        $qtyReturned = (string) $data['qty_returned'];

        try {
            $service->return($requisitionItem, $container, $qtyReturned, $unit, $actor, $data['remark'] ?? null);
        } catch (InvalidReturnException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.issue.create', $requisition)->with('status', __('requisitions.return_recorded'));
    }
}
