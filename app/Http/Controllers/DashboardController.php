<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Navigation\MenuRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * ITM-2610-052: the home page is a set of cards (see MenuRegistry), shown only for what the
 * viewer may use. An account with a single card skips the page and lands inside that card; one
 * with none gets an explanation. The old dashboard numbers/charts moved to the warehouse
 * overview (WarehouseController).
 */
final class DashboardController extends Controller
{
    public function index(MenuRegistry $menu): View|RedirectResponse
    {
        $user = auth()->user();
        if ($user === null) {
            return view('welcome');
        }

        $cards = $menu->cardsFor($user, false);
        if (count($cards) === 1) {
            return redirect()->to($cards[0]['url']);
        }

        return view('home', ['cards' => $menu->cardsFor($user)]);
    }
}
