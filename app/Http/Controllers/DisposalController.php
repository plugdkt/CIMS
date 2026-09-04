<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Inventory\Exceptions\InvalidDisposalException;
use App\Domain\Inventory\Services\DisposalService;
use App\Http\Requests\DisposalRequest;
use App\Models\Container;
use App\Models\Disposal;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** FR-ST-05: request → LAB_MANAGER approve/reject. */
final class DisposalController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Disposal::class);

        return view('disposals.create');
    }

    public function store(DisposalRequest $request, DisposalService $service): RedirectResponse
    {
        $data = $request->validated();
        $container = Container::where('barcode', $data['barcode'])->firstOrFail();

        /** @var User $requester */
        $requester = $request->user();

        /** @var numeric-string $qty */
        $qty = (string) $data['qty'];

        try {
            $disposal = $service->request($container, $qty, $data['reason'], $data['method'] ?? null, $data['disposal_date'], $requester);
        } catch (InvalidDisposalException $e) {
            return back()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('disposals.show', $disposal)->with('status', __('disposals.created'));
    }

    public function show(Disposal $disposal): View
    {
        $this->authorize('view', $disposal);

        return view('disposals.show', [
            'disposal' => $disposal->load(['container.item', 'requestedBy', 'approvedBy']),
            'canDecide' => auth()->user()?->can('decide', $disposal) ?? false,
        ]);
    }

    public function approve(Disposal $disposal, DisposalService $service): RedirectResponse
    {
        $this->authorize('decide', $disposal);

        /** @var User $approver */
        $approver = auth()->user();

        try {
            $service->approve($disposal, $approver);
        } catch (InvalidDisposalException $e) {
            return back()->withErrors(['decide' => $e->getMessage()]);
        }

        return redirect()->route('disposals.show', $disposal)->with('status', __('disposals.approved'));
    }

    public function reject(Disposal $disposal, DisposalService $service): RedirectResponse
    {
        $this->authorize('decide', $disposal);

        /** @var User $approver */
        $approver = auth()->user();

        try {
            $service->reject($disposal, $approver);
        } catch (InvalidDisposalException $e) {
            return back()->withErrors(['decide' => $e->getMessage()]);
        }

        return redirect()->route('disposals.show', $disposal)->with('status', __('disposals.rejected'));
    }
}
