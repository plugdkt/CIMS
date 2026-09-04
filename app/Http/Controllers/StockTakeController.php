<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InvalidStockTakeException;
use App\Domain\Inventory\Services\StockTakeService;
use App\Http\Requests\StockTakeCountRequest;
use App\Http\Requests\StockTakeRequest;
use App\Models\Container;
use App\Models\Lab;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** FR-ST-02..04: create a round, record counts (mobile scan), submit, approve. */
final class StockTakeController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', StockTake::class);

        return view('stock-takes.create', [
            'labs' => Lab::where('is_active', true)->orderBy('name_th')->get(),
        ]);
    }

    public function store(StockTakeRequest $request, StockTakeService $service): RedirectResponse
    {
        $data = $request->validated();
        $lab = Lab::whereKey($data['lab_id'])->firstOrFail();

        /** @var User $creator */
        $creator = $request->user();

        $stockTake = $service->create($lab, $data['count_date'], $creator);

        return redirect()->route('stock-takes.show', $stockTake)->with('status', __('stock_takes.created'));
    }

    public function show(StockTake $stockTake): View
    {
        $this->authorize('view', $stockTake);

        return view('stock-takes.show', [
            'stockTake' => $stockTake->load(['lab', 'lines.container.item', 'lines.countedBy']),
            'canCount' => auth()->user()?->can('count', $stockTake) ?? false,
            'canSubmit' => auth()->user()?->can('submit', $stockTake) ?? false,
            'canApprove' => auth()->user()?->can('approve', $stockTake) ?? false,
            'canCancel' => auth()->user()?->can('cancel', $stockTake) ?? false,
        ]);
    }

    /** FR-ST-03: the mobile-optimized scan-and-count page. */
    public function scan(StockTake $stockTake): View
    {
        $this->authorize('count', $stockTake);

        $remaining = $stockTake->lines()->whereNull('counted_qty_base')->count();
        $total = $stockTake->lines()->count();

        return view('stock-takes.scan', [
            'stockTake' => $stockTake,
            'remaining' => $remaining,
            'total' => $total,
        ]);
    }

    public function recordCount(StockTakeCountRequest $request, StockTake $stockTake, StockTakeService $service): RedirectResponse
    {
        $data = $request->validated();
        $container = Container::where('barcode', $data['barcode'])->firstOrFail();

        $line = StockTakeLine::where('stock_take_id', $stockTake->id)
            ->where('container_id', $container->id)
            ->first();

        if ($line === null) {
            return back()->withErrors(['barcode' => __('stock_takes.container_not_in_round')]);
        }

        /** @var User $counter */
        $counter = $request->user();

        /** @var numeric-string $countedQty */
        $countedQty = (string) $data['counted_qty'];

        try {
            $service->recordCount($line, $countedQty, $counter, $data['reason'] ?? null);
        } catch (InvalidStockTakeException $e) {
            return back()->withErrors(['barcode' => $e->getMessage()]);
        }

        return redirect()->route('stock-takes.scan', $stockTake)->with('status', __('stock_takes.count_recorded'));
    }

    public function submit(StockTake $stockTake, StockTakeService $service): RedirectResponse
    {
        $this->authorize('submit', $stockTake);

        try {
            $service->submitForApproval($stockTake);
        } catch (InvalidStockTakeException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()->route('stock-takes.show', $stockTake)->with('status', __('stock_takes.submitted'));
    }

    public function approve(StockTake $stockTake, StockTakeService $service): RedirectResponse
    {
        $this->authorize('approve', $stockTake);

        /** @var User $approver */
        $approver = auth()->user();

        try {
            $service->approve($stockTake, $approver);
        } catch (InvalidStockTakeException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return redirect()->route('stock-takes.show', $stockTake)->with('status', __('stock_takes.approved'));
    }

    public function cancel(StockTake $stockTake, StockTakeService $service): RedirectResponse
    {
        $this->authorize('cancel', $stockTake);

        try {
            $service->cancel($stockTake);
        } catch (InvalidStockTakeException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('stock-takes.index')->with('status', __('stock_takes.cancelled'));
    }
}
