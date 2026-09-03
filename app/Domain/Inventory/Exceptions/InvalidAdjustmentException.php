<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** BR-06: a ledger adjustment that doesn't meet the rule (remark, distinct approver) is invalid. */
final class InvalidAdjustmentException extends RuntimeException
{
}
