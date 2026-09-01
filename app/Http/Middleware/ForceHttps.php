<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects plain HTTP to HTTPS (SEC-CR-01). Skipped in local/testing so `docker compose`
 * dev (plain http://localhost:8090) and the test suite don't need a TLS cert.
 */
final class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure() && ! app()->environment('local', 'testing')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
