<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

use Illuminate\Support\Carbon;

/**
 * One ledger row already resolved to a display unit (FR-LG-03) — the shared shape
 * behind the on-screen ledger (T-024) and the F-03 PDF/Excel exports (T-025).
 */
final readonly class LedgerRow
{
    public function __construct(
        public int $id,
        public Carbon $txnDate,
        public string $txnType,
        public ?string $issuerName,
        public ?string $receiverName,
        public ?string $qtyIn,
        public ?string $qtyOut,
        public string $balance,
        public bool $signed,
        public ?string $remark,
    ) {
    }
}
