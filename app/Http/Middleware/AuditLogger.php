<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generic safety net (SEC-LG-01, SEC-LG-05): every state-changing request, and every
 * authentication/authorization failure, gets a baseline audit_logs row automatically —
 * even from a route nobody remembered to instrument by hand.
 *
 * Rich, entity-level detail for specific business events (login success, requisition
 * approved, ledger write, report downloaded, attachment opened) is logged separately by
 * the service that performs the action, via App\Models\AuditLog::record(), because only
 * that call site knows which entity/old-value/new-value belong in the row.
 *
 * Runs as terminable middleware so it never delays the response to the client.
 */
final class AuditLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        $isMutating = ! $request->isMethod('GET') && ! $request->isMethod('HEAD');
        $isAuthFailure = in_array($status, [401, 403, 419, 429], true);

        if (! $isMutating && ! $isAuthFailure) {
            return;
        }

        AuditLog::record(
            action: strtoupper($request->method()).' '.($request->route()?->getName() ?? $request->path()),
            result: $status < 400 ? 'SUCCESS' : 'FAILURE',
            userId: $request->user()?->id,
            username: $request->user()?->username,
            message: $status >= 400 ? "HTTP {$status}" : null,
        );
    }
}
