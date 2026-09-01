<?php

declare(strict_types=1);

namespace App\Domain\Attachments\Contracts;

/** SEC-FU-04: scan every upload before it's saved permanently. */
interface VirusScanner
{
    public function isClean(string $absolutePath): bool;
}
