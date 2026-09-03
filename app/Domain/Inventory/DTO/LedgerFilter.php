<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

/** FR-LG-04: the ledger filter set — date range, transaction type, requester, container. */
final readonly class LedgerFilter
{
    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $txnType = null,
        public ?string $receiverName = null,
        public ?string $containerBarcode = null,
    ) {
    }
}
