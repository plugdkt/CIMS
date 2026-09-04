<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InvalidAdjustmentException;
use App\Domain\Inventory\Services\AdjustmentService;
use App\Http\Requests\AdjustmentRequest;
use App\Models\Container;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** FR-LG-07 / BR-06: the adjustment list + a single-step create-and-approve form. */
final class AdjustmentController extends Controller
{
    public function index(): View
    {
        $this->authorize('adjust', StockLedger::class);

        $adjustments = StockLedger::with(['item', 'container', 'creator', 'approver'])
            ->whereIn('txn_type', ['ADJUST_IN', 'ADJUST_OUT'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('adjustments.index', ['adjustments' => $adjustments]);
    }

    public function create(): View
    {
        $this->authorize('adjust', StockLedger::class);

        /** @var User $user */
        $user = auth()->user();

        $approvers = User::whereHas('roles.permissions', fn ($q) => $q->where('code', 'ledger.adjust'))
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->orderBy('full_name')
            ->get();

        return view('adjustments.create', ['approvers' => $approvers]);
    }

    public function store(AdjustmentRequest $request, AdjustmentService $service): RedirectResponse
    {
        $data = $request->validated();
        $container = Container::where('barcode', $data['barcode'])->firstOrFail();
        $approver = User::whereKey($data['approved_by'])->firstOrFail();

        /** @var User $creator */
        $creator = $request->user();

        /** @var numeric-string $qty */
        $qty = (string) $data['qty'];
        $signedQty = $data['direction'] === 'OUT' ? bcmul($qty, '-1', 6) : $qty;

        try {
            $service->adjust($container, $signedQty, $data['remark'], $creator, $approver);
        } catch (InvalidAdjustmentException $e) {
            return back()->withErrors(['adjustment' => $e->getMessage()]);
        }

        return redirect()->route('adjustments.index')->with('status', __('adjustments.created'));
    }
}
