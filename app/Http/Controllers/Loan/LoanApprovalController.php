<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanApprovalService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanAdvisorDecisionRequest;
use App\Http\Requests\Loan\LoanListRequest;
use App\Http\Requests\Loan\LoanManagerDecisionRequest;
use App\Http\Requests\Loan\LoanSignedDecisionRequest;
use App\Models\Loan;
use App\Models\LoanDueChange;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-051 step 2: the approval side — the queue page, the logged-in advisor's and manager's decisions, and the
 * advisor's emailed signed link (FR-LN-12, public: the signed URL is the only credential and the service decides
 * whether that advisor may act).
 */
final class LoanApprovalController extends Controller
{
    /** The queue: the advisor's students' requests, and (approvers of a branch) the requests waiting for a manager. */
    public function queue(LoanListRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $advisorQueue = collect();
        if ($user->hasPermission('loan.approve_advisor')) {
            $advisorQueue = Loan::query()
                ->with(['borrower', 'lab', 'lines.asset.item'])
                ->where('advisor_id', $user->id)
                ->where('status', 'SUBMITTED')
                ->where('borrower_status', 'STUDENT')
                ->orderBy('submitted_at')
                ->limit(100)
                ->get();
        }

        $managerQueue = collect();
        if ($user->hasPermission('loan.approve') && $user->lab_id !== null) {
            $managerQueue = Loan::query()
                ->with(['borrower', 'lab', 'lines.asset.item'])
                ->where('lab_id', $user->lab_id)
                ->where(function ($q) {
                    $q->where('status', 'ADVISOR_APPROVED')
                        ->orWhere(fn ($w) => $w->where('status', 'SUBMITTED')->where('borrower_status', '!=', 'STUDENT'));
                })
                // Not the ones this user typed in for somebody else (they may not approve those); their own are listed.
                ->where(function ($q) use ($user) {
                    $q->whereColumn('created_by', 'borrower_id')->orWhere('created_by', '!=', $user->id);
                })
                ->orderBy('submitted_at')
                ->limit(100)
                ->get();
        }

        // Step 3 (BR-L07): renewals of the branch that wait for an approver (the 2nd and later ones), loans still out.
        $renewalQueue = collect();
        if ($user->hasPermission('loan.approve') && $user->lab_id !== null) {
            $renewalQueue = LoanDueChange::query()
                ->with(['loan.borrower:id,full_name'])
                ->where('status', 'PENDING')
                ->whereHas('loan', fn ($q) => $q->where('lab_id', $user->lab_id)->whereIn('status', Loan::OUT))
                ->orderBy('created_at')
                ->limit(100)
                ->get();
        }

        return view('loans.queue', ['advisorQueue' => $advisorQueue, 'managerQueue' => $managerQueue, 'renewalQueue' => $renewalQueue]);
    }

    public function advisorDecide(LoanAdvisorDecisionRequest $request, Loan $loan, LoanApprovalService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $loan = $service->advisorDecide($loan, $user, $data['decision'], $data['reason'] ?? null, $request->ip());
        } catch (LoanDomainException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        $notifier->advisorDecided($loan, $data['decision'], $user);

        return redirect()->route('loans.show', $loan)->with('status', __('loans.advisor_decision_recorded'));
    }

    public function managerDecide(LoanManagerDecisionRequest $request, Loan $loan, LoanApprovalService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        try {
            $loan = $service->managerDecide($loan, $user, $data['decision'], $data['reason'] ?? null, $request->ip(), $data['qty_approved'] ?? []);
        } catch (LoanDomainException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        $notifier->managerDecided($loan, $data['decision'], $user);

        return redirect()->route('loans.show', $loan)->with('status', __('loans.manager_decision_recorded'));
    }

    /** GET /loan-approve/{loan} — public, gated by the `signed` middleware only. Shows nothing once the step is over. */
    public function showSigned(Loan $loan): View
    {
        if ($loan->status !== 'SUBMITTED' || ! $loan->isStudentBorrower()) {
            return view('loans.approve-signed-result', ['status' => __('loans.signed_not_waiting'), 'failed' => true]);
        }

        $loan->load(['borrower:id,full_name', 'lines.asset.item']);

        return view('loans.approve-signed', ['loan' => $loan]);
    }

    /** POST /loan-approve/{loan} — the same signed URL as the page's form action. */
    public function decideSigned(LoanSignedDecisionRequest $request, Loan $loan, LoanApprovalService $service, LoanNotifier $notifier): View
    {
        $data = $request->validated();
        $advisor = $loan->advisor_id === null ? null : User::with('roles.permissions')->find($loan->advisor_id);

        try {
            if ($advisor === null) {
                throw new LoanDomainException((string) __('loans.error.advisor_not_eligible'));
            }
            $decided = $service->advisorDecide($loan, $advisor, $data['decision'], $data['reason'] ?? null, $request->ip());
            $notifier->advisorDecided($decided, $data['decision'], $advisor);
            $status = (string) __('loans.advisor_decision_recorded');
            $failed = false;
        } catch (LoanDomainException $e) {
            $status = $e->getMessage();
            $failed = true;
        }

        return view('loans.approve-signed-result', ['status' => $status, 'failed' => $failed]);
    }
}
