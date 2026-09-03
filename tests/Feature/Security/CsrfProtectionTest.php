<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

/**
 * ST-03: POST without a CSRF token -> HTTP 419.
 *
 * Laravel's own CSRF middleware self-disables while running unit tests
 * (`PreventRequestForgery::runningUnitTests()`), so a plain `$this->post(...)`
 * through the router can never exercise this control. This test drives the
 * exact middleware class the default 'web' group registers directly, with
 * that one bypass turned off, so it's the real token-matching logic under
 * test — not a framework testing artifact.
 */
test('a POST request without a valid CSRF token is rejected (ST-03)', function () {
    $middleware = new class (app(), app('encrypter')) extends PreventRequestForgery {
        protected function runningUnitTests()
        {
            return false;
        }
    };

    app('session.store')->start();

    $request = Request::create('/items', 'POST', ['name_th' => 'x']);
    $request->setLaravelSession(app('session.store'));

    expect(fn () => $middleware->handle($request, fn ($req) => response('ok')))
        ->toThrow(TokenMismatchException::class);
});
