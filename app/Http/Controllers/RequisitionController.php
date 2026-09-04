<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\Services\Fr01PdfService;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\RequisitionService;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Http\Requests\RequisitionRequest;
use App\Models\Item;
use App\Models\Lab;
use App\Models\Requisition;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class RequisitionController extends Controller
{
    /**
     * BR-11 point 4: STUDENT/STAFF must complete their profile before their first
     * requisition — the only enforcement point for this gate (see CLAUDE.md; it's
     * deliberately not a global post-login middleware).
     */
    public function create(): View|RedirectResponse
    {
        $this->authorize('create', Requisition::class);

        /** @var User $user */
        $user = auth()->user();
        if ($user->profile_completed_at === null) {
            return redirect()->route('account.complete-profile')
                ->with('status', __('requisitions.complete_profile_first'));
        }

        return view('requisitions.create', [
            'labs' => Lab::where('is_active', true)->orderBy('name_th')->get(),
        ]);
    }

    public function store(RequisitionRequest $request, DocumentNumberGenerator $generator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $requisition = Requisition::create(array_merge($request->validated(), [
            'doc_no' => $generator->next('REQ'),
            'doc_date' => now()->toDateString(),
            'requester_id' => $user->id,
            'requester_status' => $user->person_type,
            'requester_phone' => $user->phone_encrypted,
            'student_code' => $user->person_type === 'STUDENT' ? $user->person_code_encrypted : null,
            'program' => $user->program,
            'faculty' => $user->faculty,
            'advisor_id' => $user->advisor_id,
            'status' => 'DRAFT',
        ]));

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.created'));
    }

    public function show(Requisition $requisition): View
    {
        $this->authorize('view', $requisition);

        return view('requisitions.show', [
            'requisition' => $requisition->load(['lab', 'requester', 'advisor', 'items.item', 'items.unit']),
            'items' => Item::where('is_active', true)->orderBy('name_th')->get(),
            'units' => Unit::orderBy('sort_order')->get(),
            'canEdit' => auth()->user()?->can('update', $requisition) ?? false,
        ]);
    }

    public function update(RequisitionRequest $request, Requisition $requisition): RedirectResponse
    {
        $requisition->update($request->validated());

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.saved'));
    }

    public function submit(Requisition $requisition, RequisitionService $service): RedirectResponse
    {
        $this->authorize('update', $requisition);

        try {
            $service->submit($requisition);
        } catch (InvalidRequisitionTransitionException $e) {
            return back()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.submitted'));
    }

    public function cancel(Requisition $requisition, RequisitionService $service): RedirectResponse
    {
        $this->authorize('cancel', $requisition);

        try {
            $service->cancel($requisition);
        } catch (InvalidRequisitionTransitionException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.index')->with('status', __('requisitions.cancelled'));
    }

    /** FR-RQ-12: printable F-01 form with a QR-verify corner (§7.2 /verify/{ulid}). */
    public function pdf(Requisition $requisition, Fr01PdfService $service): Response
    {
        $this->authorize('view', $requisition);

        $pdf = $service->render($requisition);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="F01-'.$requisition->doc_no.'.pdf"',
        ]);
    }

    /** FR-RQ-05: current balance next to the selected item, fetched as the line item changes. */
    public function itemBalance(Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        $balance = StockLedger::where('item_id', $item->id)->orderByDesc('id')->value('balance_base') ?? '0.000000';

        return response()->json([
            'balance' => $balance,
            'unit' => $item->baseUnit?->code,
        ]);
    }
}
