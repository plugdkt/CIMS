<?php

declare(strict_types=1);

namespace App\Domain\Attachments\Services;

use App\Domain\Attachments\Contracts\VirusScanner;
use RuntimeException;

/** Talks to clamd over its INSTREAM TCP protocol — no PHP extension required. */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK_SIZE = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds = 10.0,
    ) {
    }

    public function isClean(string $absolutePath): bool
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            throw new RuntimeException("Cannot reach ClamAV at {$this->host}:{$this->port}: {$errstr}");
        }

        try {
            fwrite($socket, "zINSTREAM\0");

            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Cannot open file for scanning: {$absolutePath}");
            }

            try {
                while (! feof($handle)) {
                    $chunk = fread($handle, self::CHUNK_SIZE);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    fwrite($socket, pack('N', strlen($chunk)).$chunk);
                }
            } finally {
                fclose($handle);
            }

            fwrite($socket, pack('N', 0));

            $response = trim((string) fread($socket, 4096));

            return str_ends_with($response, 'OK');
        } finally {
            fclose($socket);
        }
    }
}
