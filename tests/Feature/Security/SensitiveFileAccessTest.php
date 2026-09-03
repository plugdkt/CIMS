<?php

/**
 * ST-06/ST-07: no direct URL ever reaches raw storage files or project internals.
 * Spec's own acceptance criterion is "HTTP 404/403" — either is a pass. Laravel's
 * built-in `storage.local` route (auto-registered in local/testing envs) actually
 * answers `/storage/{path}` with 403 for anything under the private disk root
 * (`storage/app/private`, where the `attachments` disk lives — config/filesystems.php
 * sets `'serve' => false` on it too); 404 is what a route with no name-matched IIS
 * rewrite falls back to. In production this is additionally backed by the IIS
 * document root pointing only at `public/` (`public/web.config`, T-003), so a
 * misconfigured/bypassed router isn't the only layer of defense.
 */
test('direct paths under storage are not web-accessible (ST-06)', function () {
    expect($this->get('/storage/app/private/attachments/x.pdf')->status())->toBeIn([403, 404]);
    expect($this->get('/storage/sds/x.pdf')->status())->toBeIn([403, 404]);
});

test('sensitive project files are not web-accessible (ST-07)', function () {
    $this->get('/.env')->assertStatus(404);
    $this->get('/composer.json')->assertStatus(404);
    $this->get('/vendor/autoload.php')->assertStatus(404);
});
