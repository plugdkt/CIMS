<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** FR-ST-05: an invalid disposal state transition, or a quantity exceeding the container's remaining stock. */
final class InvalidDisposalException extends RuntimeException
{
}
