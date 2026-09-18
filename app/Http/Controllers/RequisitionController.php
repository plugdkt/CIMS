<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reporting\Services\Fr01PdfService;
use App\Domain\Requisition\Exceptions\InvalidRequisitionTransitionException;
use App\Domain\Requisition\Services\RequisitionService;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Http\Requests\RequisitionRequest;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\StockLedger;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RequisitionController extends Controller
{
    /**
     * BR-11 point 4: STUDENT/STAFF must complete their profile before their first
     * requisition — the only enforcement point for this gate (see CLAUDE.md; it's
     * deliberately not a global post-login middleware). The branch-assignment gate below
     * follows the same shape: a requester with no `lab_id` yet has nothing to snapshot
     * onto the requisition, so they're redirected to wait for an ADMIN to assign one
     * (see `UserRoleManager::setLab()`), same as waiting for a role.
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

        if ($user->lab_id === null) {
            return redirect()->route('account.pending-lab');
        }

        return view('requisitions.create');
    }

    public function store(RequisitionRequest $request, DocumentNumberGenerator $generator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Defense in depth: `create()` above already redirects a requester with no
        // branch to account.pending-lab before they ever see the form, but `lab_id` is
        // NOT NULL on requisitions — refuse here too rather than letting a direct POST
        // (bypassing the GET page) hit a DB constraint violation.
        abort_if($user->lab_id === null, 403);

        $requisition = Requisition::create(array_merge($request->validated(), [
            'doc_no' => $generator->next('REQ'),
            'doc_date' => now()->toDateString(),
            'lab_id' => $user->lab_id,
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
            'units' => Unit::orderBy('sort_order')->get(),
            'canEdit' => auth()->user()?->can('update', $requisition) ?? false,
        ]);
    }

    /**
     * Type-to-search item picker for the add-line form — the catalog is thousands of
     * rows, too many for a plain <select>. Scoped to items with real stock (SEALED/
     * IN_USE, qty > 0) in the requester's own lab, same "own branch only" rule the
     * rest of the multi-branch feature already enforces elsewhere — there's nothing
     * useful to request against an item this lab has never stocked.
     *
     * An empty `q` is also a valid call (not just "nothing typed yet") — it browses
     * the requester's own lab stock instead of narrowing it, so a requester who can't
     * recall an item's exact name can still find it by looking, not just by typing.
     */
    public function itemSearch(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Item::class);

        /** @var User $user */
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        if ($user->lab_id === null) {
            return response()->json([]);
        }

        $items = Item::query()
            ->where('is_active', true)
            ->whereHas('containers', function ($query) use ($user) {
                $query->whereIn('status', ['SEALED', 'IN_USE'])
                    ->where('remaining_qty_base', '>', 0)
                    ->whereHas('location', fn ($q) => $q->where('lab_id', $user->lab_id));
            })
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($q) use ($term) {
                    $q->where('name_th', 'like', "%{$term}%")
                        ->orWhere('name_en', 'like', "%{$term}%")
                        ->orWhere('item_code', 'like', "%{$term}%");
                });
            })
            ->with('baseUnit')
            ->orderBy('name_th')
            ->limit($term === '' ? 50 : 20)
            ->get(['id', 'ulid', 'item_code', 'name_th', 'grade', 'physical_state', 'base_unit_id']);

        return response()->json($items->map(fn (Item $item) => [
            'id' => $item->id,
            'ulid' => $item->ulid,
            'label' => trim($item->name_th.' ('.implode(' | ', array_filter([
                $item->item_code,
                $item->grade ? (string) __('items.grade_label', ['grade' => $item->grade]) : null,
                $item->physical_state ? (string) __('items.state_'.$item->physical_state) : null,
            ])).')'),
            'baseUnitId' => $item->base_unit_id,
            'dimension' => $item->baseUnit?->dimension,
        ]));
    }

    public function update(RequisitionRequest $request, Requisition $requisition): RedirectResponse
    {
        $requisition->update($request->validated());

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.saved'));
    }

    public function submit(Requisition $requisition, RequisitionService $service): RedirectResponse
    {
        $this->authorize('submit', $requisition);

        if ($requisition->status !== 'DRAFT') {
            return redirect()->route('requisitions.show', $requisition);
        }

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

        // Display only — trims the DECIMAL(18,6) column's trailing zeros (e.g. "1000.000000"
        // -> "1000"). Same convention already used for the container "คงเหลือ" column
        // (LabInventoryTable / items.show); the stored/computed value itself is untouched.
        return response()->json([
            'balance' => rtrim(rtrim($balance, '0'), '.'),
            'unit' => $item->baseUnit?->code,
        ]);
    }
}
