<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\LoanCart;
use App\Domain\Loan\Services\BorrowerContext;
use App\Domain\Loan\Services\BorrowerResolver;
use App\Domain\Loan\Services\LoanAccess;
use App\Domain\Loan\Services\LoanNotifier;
use App\Domain\Loan\Services\LoanRenewalService;
use App\Domain\Loan\Services\LoanService;
use App\Domain\Loan\Services\LoanSlipPdfService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanCancelRequest;
use App\Http\Requests\Loan\LoanListRequest;
use App\Http\Requests\Loan\LoanStoreRequest;
use App\Http\Requests\Loan\LoanSubmitRequest;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * ITM-2610-051 step 2: the borrower's side — "things I borrowed", the request form, submit, cancel — plus the
 * document page everybody involved opens. Thin: validation in the Form Requests, access in LoanPolicy, rules in
 * LoanService / LoanApprovalService, notifications in LoanNotifier (sent only after the change is saved).
 */
final class LoanController extends Controller
{
    /** FR-LN-30: what I borrowed (as the borrower, or typed in for me by the counter). */
    public function mine(LoanListRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $loans = Loan::query()
            ->with(['lab', 'lines.asset.item', 'borrower'])
            ->where(function ($q) use ($user) {
                $q->where('borrower_id', $user->id)->orWhere('created_by', $user->id);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            // `%` and `_` typed in the box are literal characters, not wildcards (same as the counter's search).
            ->when($request->filled('q'), fn ($q) => $q->where('doc_no', 'like', '%'.addcslashes(trim((string) $request->query('q')), '%_\\').'%'))
            // Step 3 (FR-LN-30): only what is out and past its due date.
            ->when($request->query('overdue') === '1', fn ($q) => $q->overdue())
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('loans.mine', ['loans' => $loans, 'hasLab' => $user->lab_id !== null]);
    }

    /** The "no branch yet" page (BR-L05): a user whose `users.lab_id` is empty cannot borrow. */
    public function pendingLab(): View|RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->lab_id !== null) {
            return redirect()->route('loans.mine');
        }

        return view('loans.pending-lab');
    }

    public function create(LoanListRequest $request, BorrowerResolver $resolver, LoanService $service): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $context = $this->resolveContext($request, $user, $resolver);
        if ($context instanceof RedirectResponse) {
            return $context;
        }

        // ITM-2610-070: "ดำเนินการต่อ" from the borrowing cart (`?cart=1`) fills the lines in, for oneself only.
        $cartLines = $request->query('cart') === '1' && $context['context']->user->id === $user->id ? LoanCart::lines($user) : [];

        return view('loans.form', $this->formData($user, $context['context'], null, $request, $context['error'], $service, $cartLines));
    }

    public function edit(Loan $loan, LoanListRequest $request, BorrowerResolver $resolver, LoanService $service): View|RedirectResponse
    {
        $this->authorize('updateLoan', $loan);

        /** @var User $viewer */
        $viewer = $request->user();
        $borrower = User::with('roles.permissions')->findOrFail($loan->borrower_id);

        return view('loans.form', $this->formData($viewer, $resolver->forSelf($borrower), $loan, $request, null, $service));
    }

    public function store(LoanStoreRequest $request, LoanService $service, BorrowerResolver $resolver, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{purpose_type: string, purpose_detail?: string|null, loan_date: string, due_date: string, remark?: string|null, overdue_override_reason?: string|null, qty: array<string, string|int|null>} $data (shape guaranteed by LoanStoreRequest) */
        $data = $request->validated();

        try {
            $onBehalf = $request->onBehalfBorrowerId();
            if ($onBehalf === null) {
                if ($gate = $this->profileGate($user)) {
                    return $gate;
                }
                $context = $resolver->forSelf($user);
            } else {
                $context = $resolver->forOnBehalf($user, $onBehalf);
            }

            $loan = $service->createDraft($user, $context, $data, $data['qty'], $data['overdue_override_reason'] ?? null);
            // ITM-2610-070: a request for oneself made from the borrowing cart empties the cart once it is saved.
            if ($onBehalf === null && $request->input('from_cart') === '1') {
                LoanCart::forget($user);
            }
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['loan' => $e->getMessage()]);
        }

        return $this->afterSave($request, $loan, $user, $service, $notifier);
    }

    public function update(LoanStoreRequest $request, Loan $loan, LoanService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{purpose_type: string, purpose_detail?: string|null, loan_date: string, due_date: string, remark?: string|null, qty: array<string, string|int|null>} $data (shape guaranteed by LoanStoreRequest) */
        $data = $request->validated();

        try {
            $loan = $service->updateDraft($user, $loan, $data, $data['qty']);
        } catch (LoanDomainException $e) {
            return back()->withInput()->withErrors(['loan' => $e->getMessage()]);
        }

        return $this->afterSave($request, $loan, $user, $service, $notifier);
    }

    public function show(Loan $loan, LoanRenewalService $renewals, LoanService $service, LoanAccess $access): View
    {
        $this->authorize('viewLoan', $loan);

        $loan->load([
            'lab', 'borrower', 'creator', 'advisor', 'approver', 'lines.asset.item', 'approvals.actor',
            // Step 3: the due-date history and what was handed out / taken back on this document.
            'dueChanges.requester:id,full_name', 'dueChanges.approver:id,full_name',
            'movements.creator:id,full_name', 'movements.asset.item',
            // Step 4: the damage / loss cases (kind, quantity, state only on this page; no file, no value).
            'incidents',
        ]);

        /** @var User $viewer */
        $viewer = auth()->user();

        return view('loans.show', [
            'loan' => $loan,
            'latestRenewDue' => $renewals->latestPossibleDue($loan),
            // The same number LoanRenewalService::request() enforces (every request counts, rejected ones too).
            'renewRequestMax' => min(255, max(1, (int) config('loans.max_renewal_requests', 6))),
            // Step 4: the incident pages (description, files, reference, value) are never for the borrower.
            'canSeeIncidents' => $access->canSeeIncidentFiles($viewer, $loan),
            // BR-L08, on a DRAFT: is the borrower blocked, and may this viewer allow the request anyway?
            'blockingLoans' => $loan->status === 'DRAFT' ? $service->blockingOverdueLoans($loan->borrower_id) : [],
            'mayOverrideBlock' => $loan->status === 'DRAFT' && $service->mayOverrideOverdueBlock($viewer, $loan->borrower_id, (int) $loan->lab_id),
        ]);
    }

    /** FR-LN-25: the printable loan slip with a QR code to the public /verify/{ulid} page. */
    public function pdf(Loan $loan, LoanSlipPdfService $service): Response
    {
        $this->authorize('printLoan', $loan);

        return response($service->render($loan), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$loan->doc_no.'.pdf"',
        ]);
    }

    public function submit(LoanSubmitRequest $request, Loan $loan, LoanService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $loan = $service->submit($user, $loan, $request->input('overdue_override_reason'));
        } catch (LoanDomainException $e) {
            return redirect()->route('loans.show', $loan)->withErrors(['loan' => $e->getMessage()]);
        }

        $notifier->submitted($loan);

        return redirect()->route('loans.show', $loan)->with('status', __('loans.submitted'));
    }

    public function cancel(LoanCancelRequest $request, Loan $loan, LoanService $service, LoanNotifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $loan = $service->cancel($user, $loan, $request->input('reason'));
        } catch (LoanDomainException $e) {
            return redirect()->route('loans.show', $loan)->withErrors(['loan' => $e->getMessage()]);
        }

        $notifier->cancelled($loan, $user);

        return redirect()->route('loans.show', $loan)->with('status', __('loans.cancelled'));
    }

    /** "Save draft" or "Save and submit" (the same form, two buttons). */
    private function afterSave(LoanStoreRequest $request, Loan $loan, User $user, LoanService $service, LoanNotifier $notifier): RedirectResponse
    {
        if ($request->input('action') !== 'submit') {
            return redirect()->route('loans.show', $loan)->with('status', __('loans.draft_saved'));
        }

        try {
            $loan = $service->submit($user, $loan, $request->input('overdue_override_reason'));
        } catch (LoanDomainException $e) {
            // The draft is saved; only the submit was refused, so the user lands on the draft with the reason.
            return redirect()->route('loans.show', $loan)->withErrors(['loan' => $e->getMessage()]);
        }

        $notifier->submitted($loan);

        return redirect()->route('loans.show', $loan)->with('status', __('loans.submitted'));
    }

    /** The borrower's own profile must be ready before anything can be requested (the old personTypeGate/advisorGate idea). */
    private function profileGate(User $user): ?RedirectResponse
    {
        if ($user->lab_id === null) {
            return redirect()->route('loans.pending-lab');
        }
        if ($user->person_type === null || ($user->person_type === 'STUDENT' && $user->advisor_id === null)) {
            return redirect()->route('account.complete-profile')->with('status', __('loans.profile_incomplete'));
        }

        return null;
    }

    /**
     * Who is borrowing on this page: the user, or (counter staff, `?borrower=<ulid>`) somebody of their branch.
     *
     * @return RedirectResponse|array{context: BorrowerContext, error: string|null}
     */
    private function resolveContext(LoanListRequest $request, User $user, BorrowerResolver $resolver): RedirectResponse|array
    {
        $ulid = $request->query('borrower');
        if (is_string($ulid) && $ulid !== '' && $user->can('createOnBehalf', Loan::class)) {
            $borrower = User::where('ulid', $ulid)->first();
            try {
                if ($borrower === null) {
                    throw new LoanDomainException((string) __('loans.error.borrower_invalid'));
                }

                return ['context' => $resolver->forOnBehalf($user, $borrower->id), 'error' => null];
            } catch (LoanDomainException $e) {
                // Fall back to borrowing for oneself, with the reason shown above the form.
                $gate = $this->profileGate($user);

                return $gate ?? ['context' => $resolver->forSelf($user), 'error' => $e->getMessage()];
            }
        }

        $gate = $this->profileGate($user);
        if ($gate !== null) {
            return $gate;
        }

        return ['context' => $resolver->forSelf($user), 'error' => null];
    }

    /**
     * @param  array<string, int>  $cartLines  ITM-2610-070: lines of the borrowing cart to fill in (new request for oneself only)
     * @return array<string, mixed>
     */
    private function formData(User $viewer, BorrowerContext $context, ?Loan $loan, LoanListRequest $request, ?string $error, LoanService $service, array $cartLines = []): array
    {
        $term = trim((string) $request->query('q', ''));

        $assets = collect();
        if ($context->labId !== null) {
            $assets = LoanAsset::query()
                ->with(['item.baseUnit', 'location'])
                ->where('status', 'ACTIVE')
                ->where('lab_id', $context->labId)
                // BR-L15: nothing with no piece free is offered (static SQL, no user input in it).
                ->whereRaw('qty_total - qty_on_loan - qty_unavailable > 0')
                ->when($term !== '', function ($q) use ($term) {
                    // `%` and `_` typed in the box are literal characters, not wildcards (same as the counter's search).
                    $term = addcslashes($term, '%_\\');
                    $q->where(function ($w) use ($term) {
                        $w->where('asset_tag', 'like', "%{$term}%")
                            ->orWhereHas('item', fn ($i) => $i->where(
                                fn ($x) => $x->where('name_th', 'like', "%{$term}%")
                                    ->orWhere('name_en', 'like', "%{$term}%")
                                    ->orWhere('item_code', 'like', "%{$term}%")
                            ));
                    });
                })
                ->orderBy('id')
                ->limit(200)
                ->get();
        }

        // What the draft already asks for, so an edit shows it (assets that are fully out are still listed for the draft).
        $existing = [];
        if ($loan !== null) {
            foreach ($loan->lines()->with('asset')->get() as $line) {
                if ($line->asset !== null) {
                    $existing[$line->asset->ulid] = rtrim(rtrim((string) $line->qty, '0'), '.');
                    if (! $assets->contains('id', $line->asset->id)) {
                        $line->asset->loadMissing(['item.baseUnit', 'location']);
                        $assets->push($line->asset);
                    }
                }
            }
        }

        // ITM-2610-070: the cart's lines, filled in. An asset the search left out is still listed so its line is sent;
        // one that is no longer ACTIVE or not of the borrower's branch is not offered (LoanService refuses it anyway).
        $fromCart = $loan === null && $cartLines !== [] && $context->labId !== null;
        if ($fromCart) {
            $cartAssets = LoanAsset::with(['item.baseUnit', 'location'])
                ->whereIn('ulid', array_keys($cartLines))
                ->where('status', 'ACTIVE')
                ->where('lab_id', $context->labId)
                ->get();
            foreach ($cartAssets as $cartAsset) {
                $existing[$cartAsset->ulid] = (string) $cartLines[$cartAsset->ulid];
                if (! $assets->contains('id', $cartAsset->id)) {
                    $assets->push($cartAsset);
                }
            }
        }

        $candidates = collect();
        $bq = trim((string) $request->query('bq', ''));
        if ($loan === null && $bq !== '' && $viewer->can('createOnBehalf', Loan::class)) {
            $candidates = User::query()
                ->where('is_active', true)
                ->where('id', '!=', $viewer->id)
                ->whereNotNull('lab_id')
                ->when(! $viewer->hasRole('ADMIN'), fn ($q) => $q->where('lab_id', $viewer->lab_id))
                ->whereHas('roles.permissions', fn ($q) => $q->where('code', 'loan.create'))
                ->where(function ($q) use ($bq) {
                    $like = '%'.addcslashes($bq, '%_\\').'%';
                    $q->where('full_name', 'like', $like)->orWhere('username', 'like', $like);
                })
                ->with('lab:id,code')
                ->orderBy('full_name')
                ->limit(15)
                ->get(['id', 'ulid', 'full_name', 'username', 'lab_id']);
        }

        return [
            'loan' => $loan,
            'context' => $context,
            'borrowerLab' => $context->labId === null ? null : Lab::find($context->labId),
            'assets' => $assets,
            'existing' => $existing,
            'term' => $term,
            'bq' => $bq,
            'candidates' => $candidates,
            'canOnBehalf' => $loan === null && $viewer->can('createOnBehalf', Loan::class),
            'onBehalf' => $context->user->id !== $viewer->id || ($loan !== null && $loan->isOnBehalf()),
            'error' => $error,
            'fromCart' => $fromCart,
            // BR-L08 (step 3): the borrower's long-overdue loans (not empty = blocked) and whether the person at the
            // keyboard may allow this one request (an approver of the borrower's branch, never the borrower).
            'blockingLoans' => $service->blockingOverdueLoans($context->user->id),
            'mayOverrideBlock' => $service->mayOverrideOverdueBlock($viewer, $context->user->id, $context->labId),
            'blockDays' => (int) config('loans.overdue_block_days', 7),
            'defaultDays' => (int) config('loans.default_loan_days', 7),
            'maxDays' => (int) config('loans.max_loan_days', 30),
        ];
    }
}
