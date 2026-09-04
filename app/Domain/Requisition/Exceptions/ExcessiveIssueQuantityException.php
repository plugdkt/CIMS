<?php

declare(strict_types=1);

namespace App\Domain\Requisition\Exceptions;

use RuntimeException;

/** BR-04: qty_issued exceeding qty_requested by more than 10% needs a LAB_MANAGER's approval. */
final class ExcessiveIssueQuantityException extends RuntimeException
{
}
