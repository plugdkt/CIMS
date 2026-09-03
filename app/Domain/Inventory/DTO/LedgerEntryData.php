<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

/** Context for a single `LedgerService` write — never carries Request/Response (spec §4.2). */
final readonly class LedgerEntryData
{
    public function __construct(
        public int $displayUnitId,
        public int $createdBy,
        public ?string $refType = null,
        public ?int $refId = null,
        public ?string $refDocNo = null,
        public ?int $issuerId = null,
        public ?int $receiverId = null,
        public ?string $receiverName = null,
        public ?string $signatureHash = null,
        public ?string $remark = null,
        public ?int $approvedBy = null,
    ) {
    }
}
