<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** An approval decision that is not allowed, e.g. a REJECT with no reason. (BR-02, advisor-first, is removed.) */
final class InvalidApprovalDecisionException extends RuntimeException
{
}
