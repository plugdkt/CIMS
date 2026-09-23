<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Requisition\Exceptions\InvalidApprovalDecisionException;
use App\Domain\Requisition\Services\ApprovalService;
use App\Http\Requests\RequisitionApprovalRequest;
use App\Http\Requests\RequisitionScientistDecisionRequest;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** FR-RQ-07: the advisor's two decision channels — a 72-hour signed email link, or logged in. */
final class RequisitionApprovalController extends Controller
{
    /** GET /approve/{requisition} — public, gated by the `signed` route middleware only. */
    public function showSigned(Requisition $requisition): View
    {
        return view('requisitions.approve-signed', ['requisition' => $requisition->load(['requester', 'advisor'])]);
    }

    /** POST /approve/{requisition} — same signed URL as the GET form's action. */
    public function decideSigned(RequisitionApprovalRequest $request, Requisition $requisition, ApprovalService $service): View
    {
        $data = $request->validated();

        try {
            $service->advisorDecide(
                $requisition,
                $requisition->advisor()->firstOrFail(),
                $data['decision'],
                $data['reason'] ?? null,
                $request->ip(),
            );
            $status = __('requisitions.advisor_decision_recorded');
            $failed = false;
        } catch (InvalidApprovalDecisionException $e) {
            $status = $e->getMessage();
            $failed = true;
        }

        return view('requisitions.approve-signed-result', ['status' => $status, 'failed' => $failed]);
    }

    /** POST /requisitions/{requisition}/advisor-decide — the in-system channel, logged in. */
    public function decide(RequisitionApprovalRequest $request, Requisition $requisition, ApprovalService $service): RedirectResponse
    {
        $data = $request->validated();

        try {
            $service->advisorDecide(
                $requisition,
                $requisition->advisor()->firstOrFail(),
                $data['decision'],
                $data['reason'] ?? null,
                $request->ip(),
            );
        } catch (InvalidApprovalDecisionException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.advisor_decision_recorded'));
    }

    /** POST /requisitions/{requisition}/scientist-decide — FR-RQ-08. */
    public function scientistDecide(RequisitionScientistDecisionRequest $request, Requisition $requisition, ApprovalService $service): RedirectResponse
    {
        $data = $request->validated();

        /** @var User $scientist */
        $scientist = $request->user();

        try {
            $service->scientistDecide(
                $requisition,
                $scientist,
                $data['decision'],
                $data['reason'] ?? null,
                $request->ip(),
                $data['qty_approved'] ?? [],
            );
        } catch (InvalidApprovalDecisionException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('requisitions.show', $requisition)->with('status', __('requisitions.scientist_decision_recorded'));
    }
}
