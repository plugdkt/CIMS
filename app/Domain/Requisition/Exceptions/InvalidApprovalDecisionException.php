<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** An approval decision that is not allowed, e.g. a REJECT with no reason, or BR-02: a STUDENT requisition decided before the advisor signed off. */
final class InvalidApprovalDecisionException extends RuntimeException
{
}
