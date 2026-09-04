<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** FR-ST-02..04: an invalid stock take state transition, or an approval that would violate BR-06. */
final class InvalidStockTakeException extends RuntimeException
{
}
