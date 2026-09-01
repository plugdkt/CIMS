<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Sets a per-request CSP nonce plus the security headers required by spec §9.6.
 * A production IIS deployment also sets several of these via web.config — duplicating
 * them here means the app is safe even when served directly (e.g. `php artisan serve`,
 * or the Feature/Security tests in §11.3, which hit the app without an IIS in front of it).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $nonce = Str::random(24);
        $request->attributes->set('csp_nonce', $nonce);

        /** @var BaseResponse $response */
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            // 'unsafe-eval' is a deliberate, user-approved exception (not the blanket
            // "never" spec §9.6 literally says) — Livewire 3 is built directly on
            // Alpine's expression engine, so wire:click/wire:model/x-data all evaluate
            // expressions via eval internally. There is no CSP-strict build of either.
            // See CLAUDE.md. Nonce is still required, so arbitrary injected <script>
            // tags still can't run.
            "script-src 'self' 'unsafe-eval' 'nonce-{$nonce}'",
            // 'unsafe-inline' only, no nonce (spec's explicit prohibition names
            // script-src, not style-src) — Livewire's core JS sets a few inline
            // styles itself (loading/dirty state) that a nonce can't reach, and per
            // the CSP spec a nonce present in a directive makes browsers *ignore*
            // 'unsafe-inline' in that same directive, so the two can't be combined.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(self), microphone=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
