<?php

return [

    // SEC-FU-01: allowed extensions and max size (bytes). MIME is verified against
    // the real file content (Laravel's `mimes:` rule uses finfo, not the client-sent
    // Content-Type or the extension) — SEC-FU-02.
    'max_size_bytes' => 10 * 1024 * 1024,
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'docx'],

    'disk' => 'attachments',

    // SEC-FU-04. No ClamAV daemon in this dev docker-compose — see CLAUDE.md. Tests
    // swap VirusScanner for a fake; never disable this in a real deployment.
    'virus_scan' => [
        'enabled' => env('VIRUS_SCAN_ENABLED', true),
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => env('CLAMAV_PORT', 3310),
    ],
];
