<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** FR-RC-06: a CONFIRMED GRN can never be edited; only a DRAFT can be confirmed or cancelled. */
final class InvalidGoodsReceiptStateException extends RuntimeException
{
}
