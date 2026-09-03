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
