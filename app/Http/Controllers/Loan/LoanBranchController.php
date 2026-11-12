<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanListRequest;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * ITM-2610-051 FR-LN-31: every loan of the branch (managers: their own branch; ADMIN: any, optionally one), filtered
 * by status, borrower, item, pick-up dates and "overdue only" (step 3). Other people's DRAFTs are never listed.
 */
final class LoanBranchController extends Controller
{
    public function index(LoanListRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->hasRole('ADMIN');

        $loans = Loan::query()
            ->with(['lab', 'borrower', 'lines.asset.item'])
            ->where('status', '!=', 'DRAFT')
            ->when(! $isAdmin, fn ($q) => $q->where('lab_id', $user->lab_id))
            ->when($isAdmin && $request->filled('lab_id'), fn ($q) => $q->where('lab_id', (int) $request->query('lab_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            // `%` and `_` typed in a search box are literal characters, not wildcards (same as the counter's search).
            ->when($request->filled('q'), fn ($q) => $q->where('doc_no', 'like', '%'.addcslashes(trim((string) $request->query('q')), '%_\\').'%'))
            ->when($request->filled('borrower'), function ($q) use ($request) {
                $term = addcslashes(trim((string) $request->query('borrower')), '%_\\');
                // Nested group: a bare orWhere inside whereHas would escape the relation's own join condition.
                $q->whereHas('borrower', fn ($b) => $b->where(
                    fn ($w) => $w->where('full_name', 'like', "%{$term}%")->orWhere('username', 'like', "%{$term}%")
                ));
            })
            ->when($request->filled('item'), function ($q) use ($request) {
                $term = addcslashes(trim((string) $request->query('item')), '%_\\');
                $q->whereHas('lines.asset.item', fn ($i) => $i->where(
                    fn ($w) => $w->where('name_th', 'like', "%{$term}%")
                        ->orWhere('name_en', 'like', "%{$term}%")
                        ->orWhere('item_code', 'like', "%{$term}%")
                ));
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('loan_date', '>=', (string) $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('loan_date', '<=', (string) $request->query('to')))
            // Step 3 (FR-LN-31): only what is out and past its due date (computed, BR-L08).
            ->when($request->query('overdue') === '1', fn ($q) => $q->overdue())
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('loans.branch', [
            'loans' => $loans,
            'isAdmin' => $isAdmin,
            'labs' => $isAdmin ? Lab::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']) : collect(),
        ]);
    }
}
