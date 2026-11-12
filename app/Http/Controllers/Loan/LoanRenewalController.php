<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanNotifier;
use App\Domain\Loan\Services\LoanRenewalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanRenewDecisionRequest;
use App\Http\Requests\Loan\LoanRenewRequest;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-051 step 3 (BR-L07, FR-LN-15): asking for a later due date and deciding a waiting request. Both forms sit
 * on the loan's own page. Notifications go out only after the change is saved (LoanNotifier swallows its failures).
 */
final class LoanRenewalController extends Controller
{
    public function store(LoanRenewRequest $request, Loan $loan, LoanRenewalService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{new_due_date: string, reason?: string|null} $data (shape guaranteed by LoanRenewRequest) */
        $data = $request->validated();

        try {
            $change = $service->request($user, $loan, $data['new_due_date'], $data['reason'] ?? null);
        } catch (LoanDomainException $e) {
            return redirect()->route('loans.show', $loan)->withInput()->withErrors(['renew' => $e->getMessage()]);
        }

        $notifier->renewalRequested($loan, $change, $user);

        return redirect()->route('loans.show', $loan)
            ->with('status', __($change->status === 'APPROVED' ? 'loans.renew_auto_approved' : 'loans.renew_pending_saved'));
    }

    public function decide(LoanRenewDecisionRequest $request, Loan $loan, int $seq, LoanRenewalService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{decision: 'APPROVE'|'REJECT', note?: string|null} $data (shape guaranteed by LoanRenewDecisionRequest) */
        $data = $request->validated();

        try {
            $change = $service->decide($user, $loan, $seq, $data['decision'], $data['note'] ?? null);
        } catch (LoanDomainException $e) {
            return redirect()->route('loans.show', $loan)->withErrors(['renew' => $e->getMessage()]);
        }

        $notifier->renewalDecided($loan->refresh(), $change, $user);

        return redirect()->route('loans.show', $loan)->with('status', __('loans.renew_decision_recorded'));
    }
}
