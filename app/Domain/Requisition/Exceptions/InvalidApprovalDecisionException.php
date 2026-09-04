<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** BR-02 (a STUDENT's requisition needs advisor sign-off first) or a REJECT with no reason. */
final class InvalidApprovalDecisionException extends RuntimeException
{
}
