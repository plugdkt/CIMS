<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\StockLedger;

/** BR-08: hash chain over stock_ledger — spec §10.3, implemented verbatim. */
final class LedgerHasher
{
    public function compute(StockLedger $row): string
    {
        $payload = implode('|', [
            $row->prev_row_hash ?? '',
            $row->item_id,
            $row->txn_type,
            $row->qty_in_base,
            $row->qty_out_base,
            $row->balance_base,
            $row->created_at->format('Y-m-d\TH:i:s.u'),
            $row->created_by,
        ]);

        return hash('sha256', $payload);
    }

    /** @return array{ok: bool, broken_at: int|null} */
    public function verifyChain(int $itemId): array
    {
        $prev = null;
        $broken = null;

        StockLedger::where('item_id', $itemId)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$prev, &$broken) {
                foreach ($rows as $row) {
                    if ($row->prev_row_hash !== $prev || $row->row_hash !== $this->compute($row)) {
                        $broken = $row->id;

                        return false;
                    }
                    $prev = $row->row_hash;
                }

                return true;
            });

        return ['ok' => $broken === null, 'broken_at' => $broken];
    }
}
