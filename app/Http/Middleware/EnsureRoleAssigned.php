<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-11 point 3 / FR-AU-04 / ST-10b: a logged-in user with no role assigned yet gets
 * HTTP 403 on every route except the "waiting for admin" page and logout — deny by
 * default (SEC-AZ-01). The one-time redirect *to* that page happens right after login,
 * from SsoCallbackController — this middleware only guards everything after that.
 */
final class EnsureRoleAssigned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $exempt = ['account.pending-role', 'privacy-notice.show', 'privacy-notice.accept', 'logout', 'login', 'sso.callback'];

        if ($user !== null && $user->roles->isEmpty() && ! $request->routeIs(...$exempt)) {
            abort(403);
        }

        return $next($request);
    }
}
