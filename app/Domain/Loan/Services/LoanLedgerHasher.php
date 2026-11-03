<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Models\LoanMovement;

/**
 * ITM-2610-051 §6: the lending ledger's own hash chain, one chain per loan asset. A NEW formula for a NEW
 * table: it does not touch LedgerHasher (stock_ledger) in any way.
 *
 *   row_hash = SHA256( prev_row_hash ?? '' | loan_asset_id | movement_type | qty | qty_total_after
 *                      | qty_on_loan_after | qty_unavailable_after | created_at(Y-m-d\TH:i:s.u) | created_by )
 *
 * Quantities are used exactly as the model returns them (DECIMAL(18,6) strings, six decimals) so a row
 * written and read back hashes the same. `approved_by`, `note` and `external_ref` are not in the formula:
 * approved_by is protected by a CHECK and by loans:verify instead.
 */
final class LoanLedgerHasher
{
    public function compute(LoanMovement $row): string
    {
        $payload = implode('|', [
            $row->prev_row_hash ?? '',
            $row->loan_asset_id,
            $row->movement_type,
            $row->qty,
            $row->qty_total_after,
            $row->qty_on_loan_after,
            $row->qty_unavailable_after,
            $row->created_at->format('Y-m-d\TH:i:s.u'),
            $row->created_by,
        ]);

        return hash('sha256', $payload);
    }

    /** @return array{ok: bool, broken_at: int|null} */
    public function verifyChain(int $loanAssetId): array
    {
        $prev = null;
        $broken = null;

        LoanMovement::where('loan_asset_id', $loanAssetId)
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
