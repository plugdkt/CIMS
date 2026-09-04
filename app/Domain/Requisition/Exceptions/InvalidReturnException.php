<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** BR-05: nothing left to return on this line, returning more than was issued, or a container this line was never issued from. */
final class InvalidReturnException extends RuntimeException
{
}
