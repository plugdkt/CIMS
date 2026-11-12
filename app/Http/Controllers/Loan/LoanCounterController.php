<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\Services\LoanAccess;
use App\Domain\Loan\Services\LoanCirculationService;
use App\Domain\Loan\Services\LoanNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanHandoutRequest;
use App\Http\Requests\Loan\LoanListRequest;
use App\Http\Requests\Loan\LoanReturnRequest;
use App\Models\Loan;
use App\Models\LoanIncident;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-051 step 3: the counter — the branch's queue ("waiting for hand-out" / "still out", FR-LN-17), the
 * hand-out page (FR-LN-20) and the return page (FR-LN-21/22). Thin: validation in the Form Requests, access in
 * LoanPolicy (browseDesk / handOutLoan / receiveReturnLoan), rules in LoanCirculationService.
 *
 * Nothing here shows a price: `replacement_value` is never read by these pages (holders of `loan.view_queue` do not
 * see values).
 */
final class LoanCounterController extends Controller
{
    /**
     * The queue of the user's OWN branch. The search box takes a document number or an asset tag, so a USB barcode
     * scanner (it types and presses Enter) lands straight on the matching loan when exactly one matches.
     */
    public function desk(LoanListRequest $request, LoanAccess $access): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $labId = (int) $user->lab_id;
        $term = trim((string) $request->query('q', ''));

        $base = Loan::query()
            ->with(['borrower:id,full_name', 'lines.asset.item'])
            ->where('lab_id', $labId)
            ->when($term !== '', function ($q) use ($term) {
                // `%` and `_` typed (or scanned) are literal characters, not wildcards (same as ImsLot's search).
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(function ($w) use ($term, $like) {
                    $w->where('doc_no', 'like', $like)
                        ->orWhereHas('lines.asset', fn ($a) => $a->where('asset_tag', $term));
                });
            });

        $toHandOut = (clone $base)->where('status', 'APPROVED')->orderBy('loan_date')->orderBy('id')->limit(100)->get();
        $out = (clone $base)->whereIn('status', Loan::OUT)->orderBy('due_date')->orderBy('id')->limit(200)->get();

        if ($term !== '' && $toHandOut->count() + $out->count() === 1) {
            /** @var Loan $only */
            $only = $toHandOut->first() ?? $out->first();
            if ($user->can('handOutLoan', $only)) {
                return redirect()->route('loans.handout.form', $only);
            }
            if ($user->can('receiveReturnLoan', $only)) {
                return redirect()->route('loans.return.form', $only);
            }
        }

        return view('loans.desk', [
            'toHandOut' => $toHandOut,
            'out' => $out,
            'term' => $term,
            // A viewer of the queue who cannot act (for example an ADMIN-only account with a branch) sees no buttons.
            'canHandle' => $access->isHandlerFor($user, $labId),
            // Step 4: a link to the branch's damage / loss cases, with how many wait for a result (a count, no names).
            'canBrowseIncidents' => $user->can('browseIncidents', LoanIncident::class),
            'pendingIncidents' => LoanIncident::query()
                ->where('resolution', 'PENDING')
                ->whereHas('loan', fn ($q) => $q->where('lab_id', $labId))
                ->count(),
        ]);
    }

    public function handoutForm(Loan $loan): View
    {
        $this->authorize('handOutLoan', $loan);

        $loan->load(['lab', 'borrower:id,full_name', 'lines.asset.item']);

        return view('loans.handout', ['loan' => $loan]);
    }

    public function handout(LoanHandoutRequest $request, Loan $loan, LoanCirculationService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lines: array<int|string, array{condition?: string|null, note?: string|null}>} $data (shape guaranteed by LoanHandoutRequest) */
        $data = $request->validated();

        try {
            $loan = $service->handOut($user, $loan, $data['lines']);
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['handout' => $e->getMessage()]);
        }

        return redirect()->route('loans.show', $loan)->with('status', __('loans.handed_out'));
    }

    public function returnForm(Loan $loan): View
    {
        $this->authorize('receiveReturnLoan', $loan);

        $loan->load(['lab', 'borrower:id,full_name', 'lines.asset.item']);

        return view('loans.return', ['loan' => $loan]);
    }

    public function receiveReturn(LoanReturnRequest $request, Loan $loan, LoanCirculationService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lines: array<int|string, array{qty?: string|null, condition?: string|null, note?: string|null, outcome?: string|null, qty_damaged?: string|null, qty_lost?: string|null, incident_note?: string|null}>} $data (shape guaranteed by LoanReturnRequest) */
        $data = $request->validated();

        try {
            $loan = $service->receiveReturn($user, $loan, $data['lines']);
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        // Step 4: damaged / lost pieces became incidents. After the return is saved (a failure here cannot undo it)
        // the people of the branch who may record the result are told.
        /** @var \Illuminate\Database\Eloquent\Collection<int, LoanIncident> $incidents */
        $incidents = $loan->getRelation('recordedIncidents');
        if ($incidents->isNotEmpty()) {
            $notifier->incidentRecorded($loan, $incidents, $user);
        }

        $message = match (true) {
            $loan->status === 'CLOSED' => 'loans.returned_closed',
            $incidents->isNotEmpty() => 'loans.returned_incident',
            default => 'loans.returned_partial',
        };

        return redirect()->route('loans.show', $loan)->with('status', __($message));
    }
}
