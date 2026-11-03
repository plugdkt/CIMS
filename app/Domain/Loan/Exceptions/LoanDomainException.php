<?php

declare(strict_types=1);

namespace App\Domain\Loan\Exceptions;

use RuntimeException;

/** A lending-module business rule refused the action; the message is already a Thai, user-facing sentence. */
final class LoanDomainException extends RuntimeException
{
}
