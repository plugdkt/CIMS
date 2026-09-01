<?php

use App\Http\Middleware\AuditLogger;
use App\Http\Middleware\EnsureRoleAssigned;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', [ForceHttps::class, SecurityHeaders::class]);
        $middleware->appendToGroup('web', [EnsureRoleAssigned::class, AuditLogger::class]);
        $middleware->api(prepend: [ForceHttps::class, SecurityHeaders::class], append: [AuditLogger::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
