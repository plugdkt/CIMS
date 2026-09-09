<?php

// ST-11: response headers carry HSTS/CSP/nosniff/X-Frame-Options, never Server/X-Powered-By.

test('security headers required by spec §9.6 are present on every response (ST-11)', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Content-Security-Policy');
    expect($response->headers->has('Server'))->toBeFalse();
    expect($response->headers->has('X-Powered-By'))->toBeFalse();
});

test('HSTS is sent once the request is actually secure (ST-11)', function () {
    // Symfony's Request::create() derives HTTPS from the URL scheme itself and
    // overrides any server variable, so the request must use an https:// URL.
    $response = $this->get('https://localhost/');

    $response->assertHeader('Strict-Transport-Security');
});

test('security headers are still present on a 404 for a completely unmatched path (T-053)', function () {
    // A path matching no route at all never enters the 'web' group's middleware
    // on its own — Route::fallback() (routes/web.php) exists specifically so it
    // does. Found by a real ZAP baseline scan flagging this as a Medium-risk
    // "CSP Header Not Set" finding before the fallback route was added.
    $response = $this->get('/this-path-does-not-exist-anywhere');

    $response->assertStatus(404);
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Content-Security-Policy');
});
