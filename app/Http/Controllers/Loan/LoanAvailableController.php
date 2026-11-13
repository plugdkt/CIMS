<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\LoanCart;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanAssetListRequest;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-051 FR-LN-04: "available to lend" for one branch (the answer to "we do not know how many are
 * free"). No prices and no movement history here; own branch only, ADMIN may pick any branch. Since ITM-2610-070
 * borrowers open it too (to fill the borrowing cart) with the same columns: item, code / register tag, place, and
 * the quantities total / on loan / not ready / available (item, tag, place and available are already on their
 * request form; the counts are the CEO's "เปิดให้ผู้ยืมเห็น").
 */
final class LoanAvailableController extends Controller
{
    public function index(LoanAssetListRequest $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->hasRole('ADMIN');

        // ITM-2610-070: borrowers reach this page too. Everyone but ADMIN sees their OWN branch only (`?lab_id=` is
        // never read for them); with no branch there is nothing to show, so they go to the "no branch yet" page.
        if (! $isAdmin && $user->lab_id === null) {
            return redirect()->route('loans.pending-lab');
        }

        $labId = $isAdmin
            ? ($request->filled('lab_id') ? (int) $request->query('lab_id') : null)
            : (int) $user->lab_id;

        $assets = LoanAsset::query()
            ->with(['item.baseUnit', 'lab', 'location'])
            ->where('status', 'ACTIVE')
            ->when($labId !== null, fn ($q) => $q->where('lab_id', $labId))
            ->when($request->filled('q'), function ($q) use ($request) {
                // `%` and `_` typed in the box are literal characters, not wildcards (same as the counter's search).
                $term = addcslashes(trim((string) $request->query('q')), '%_\\');
                $q->where(function ($w) use ($term) {
                    $w->where('asset_tag', 'like', "%{$term}%")
                        ->orWhereHas('item', fn ($i) => $i->where('name_th', 'like', "%{$term}%")
                            ->orWhere('name_en', 'like', "%{$term}%")
                            ->orWhere('item_code', 'like', "%{$term}%"));
                });
            })
            ->orderBy('lab_id')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('loans.available', [
            'assets' => $assets,
            'isAdmin' => $isAdmin,
            'labs' => $isAdmin ? Lab::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name_th']) : collect(),
            // ITM-2610-070: the borrowing cart (for oneself): only a borrower who has a branch, and only for that
            // branch's assets (LoanService::assertCartLines() decides again when a line is added).
            'canCart' => $user->lab_id !== null && $user->can('createLoan', Loan::class),
            'cartLabId' => $user->lab_id === null ? null : (int) $user->lab_id,
            'cartCount' => LoanCart::count($user),
        ]);
    }
}
