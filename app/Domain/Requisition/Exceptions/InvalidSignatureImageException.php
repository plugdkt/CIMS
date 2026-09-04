<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** FR-RQ-11: the submitted canvas signature isn't a decodable PNG data URL, or is too large. */
final class InvalidSignatureImageException extends RuntimeException
{
}
