<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\SsoClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SsoLogoutController extends Controller
{
    public function __invoke(Request $request, SsoClient $sso): RedirectResponse
    {
        // SEC-AU-09: invalidate the local session server-side, then hand off to SLO —
        // not just clearing the cookie client-side.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away($sso->logoutUrl(url('/login')));
    }
}
