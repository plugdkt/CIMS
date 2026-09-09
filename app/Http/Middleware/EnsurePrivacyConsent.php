<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-PD-02: a logged-in user who hasn't consented to the current Privacy Notice
 * version gets redirected to it on every request except the notice itself and the
 * bare login/logout endpoints — mirrors `EnsureRoleAssigned`'s exemption-list shape,
 * but redirects (there's something the user can do about it immediately) rather than
 * aborting 403 (which fits `EnsureRoleAssigned` better, since only an admin can fix that).
 */
final class EnsurePrivacyConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $exempt = ['privacy-notice.show', 'privacy-notice.accept', 'logout', 'login', 'sso.callback'];

        if ($user !== null && ! $user->hasValidPrivacyConsent() && ! $request->routeIs(...$exempt)) {
            return redirect()->route('privacy-notice.show');
        }

        return $next($request);
    }
}
