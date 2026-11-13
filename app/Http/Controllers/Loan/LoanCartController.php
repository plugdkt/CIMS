<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loan;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Loan\LoanCart;
use App\Domain\Loan\Services\BorrowerResolver;
use App\Domain\Loan\Services\LoanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\LoanCartAddRequest;
use App\Http\Requests\Loan\LoanCartRequest;
use App\Http\Requests\Loan\LoanCartUpdateRequest;
use App\Models\LoanAsset;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-070: the borrowing cart ("ตะกร้ายืม"), built like the shop's basket (ShopController add / cartPage /
 * update / remove). Assets are picked on the "available to lend" page; "ดำเนินการต่อ" opens the ordinary request
 * form with the cart's lines filled in, and the user adds purpose and dates there. Saving and submitting go through
 * LoanController::store() and LoanService unchanged, so the cart itself decides nothing.
 *
 * Borrowing for oneself only (BorrowerResolver::forSelf). Whether a line may go in is LoanService::assertCartLines():
 * the same rules as a request (own branch, ACTIVE, whole quantity, UNIT = 1) plus "not more than available now".
 * The cart lives in the session (LoanCart), so nothing is stored or reserved.
 */
final class LoanCartController extends Controller
{
    /** The largest quantity LoanStoreRequest / LoanService accept on one line (9 digits). */
    private const MAX_QTY = 999999999;

    public function index(LoanCartRequest $request, LoanService $service, BorrowerResolver $resolver): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->lab_id === null) {
            return redirect()->route('loans.pending-lab');
        }

        $context = $resolver->forSelf($user);
        $cart = LoanCart::lines($user);
        // Details (name, tag, place, availability) only of assets the user may borrow now: ACTIVE and of their own
        // branch. A line left from another branch (the user moved), a retired or a vanished asset shows nothing of
        // the asset, only a generic label, the reason and "นำออก" (QA ITM-2610-070).
        $assets = LoanAsset::with(['item.baseUnit', 'location'])
            ->whereIn('ulid', array_keys($cart))
            ->where('status', 'ACTIVE')
            ->where('lab_id', (int) $user->lab_id)
            ->get()
            ->keyBy('ulid');

        $lines = [];
        foreach ($cart as $ulid => $qty) {
            $problem = null;
            try {
                $service->assertCartLines($context, [$ulid => (string) $qty]);
            } catch (LoanDomainException $e) {
                $problem = $e->getMessage();
            }
            $lines[] = ['ulid' => $ulid, 'asset' => $assets->get($ulid), 'qty' => $qty, 'problem' => $problem];
        }

        return view('loans.cart', ['lines' => $lines]);
    }

    public function add(LoanCartAddRequest $request, LoanService $service, BorrowerResolver $resolver): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->lab_id === null) {
            return redirect()->route('loans.pending-lab');
        }

        $ulid = (string) $request->validated('asset');
        $cart = LoanCart::lines($user);

        // Adding the same asset again adds to its line (like the shop); a new line must fit in one loan document.
        if (! isset($cart[$ulid]) && count($cart) >= $service->maxLines()) {
            return back()->withErrors(['cart' => __('loans.error.too_many_lines', ['max' => $service->maxLines()])]);
        }
        // Capped at the largest quantity a request accepts (9 digits), so adding again and again ends in "not enough
        // available" (or the branch / retired reason) rather than a confusing "whole number" error.
        $qty = min(self::MAX_QTY, ($cart[$ulid] ?? 0) + (int) $request->validated('qty'));

        try {
            $assets = $service->assertCartLines($resolver->forSelf($user), [$ulid => (string) $qty]);
        } catch (LoanDomainException $e) {
            return back()->withErrors(['cart' => $e->getMessage()]);
        }

        $cart[$ulid] = $qty;
        LoanCart::put($user, $cart);

        return back()->with('status', __('loans.cart.added', ['item' => (string) $assets[$ulid]->item?->name_th]));
    }

    public function update(LoanCartUpdateRequest $request, string $asset, LoanService $service, BorrowerResolver $resolver): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = LoanCart::lines($user);
        abort_unless(isset($cart[$asset]), 404);

        $qty = (int) $request->validated('qty');
        try {
            $service->assertCartLines($resolver->forSelf($user), [$asset => (string) $qty]);
        } catch (LoanDomainException $e) {
            return redirect()->route('loans.cart')->withErrors(['cart' => $e->getMessage()]);
        }

        $cart[$asset] = $qty;
        LoanCart::put($user, $cart);

        return redirect()->route('loans.cart')->with('status', __('loans.cart.updated'));
    }

    /** A line can always be taken out, even when its asset was retired or moved since it was put in. */
    public function remove(LoanCartRequest $request, string $asset): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = LoanCart::lines($user);
        unset($cart[$asset]);
        LoanCart::put($user, $cart);

        return redirect()->route('loans.cart')->with('status', __('loans.cart.removed'));
    }
}
