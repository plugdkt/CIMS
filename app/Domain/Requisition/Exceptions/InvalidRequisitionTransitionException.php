<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** BR-01: the requested status transition isn't a legal edge in the requisition state machine. */
final class InvalidRequisitionTransitionException extends RuntimeException
{
}
