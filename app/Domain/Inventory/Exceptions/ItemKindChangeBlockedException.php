<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/** An item cannot move to / from science (loan) while an unfinished requisition still lists it. The message is Thai and names the documents. */
final class ItemKindChangeBlockedException extends RuntimeException
{
    /** @param list<string> $docNos */
    public function __construct(string $message, public readonly array $docNos = [])
    {
        parent::__construct($message);
    }
}
