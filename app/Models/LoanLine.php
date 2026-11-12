<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ITM-2610-051 step 2: one requested line. `qty` is what was asked for; `qty_approved` is set by a manager's
 * decision (never mass-assigned). Step 3: `condition_out` / `condition_out_note` are written at hand-out and
 * `qty_returned_ok` at each return, by LoanCirculationService only (forceFill, never from a request).
 *
 * @property int $id
 * @property int $loan_id
 * @property int $loan_asset_id
 * @property int $line_no
 * @property numeric-string $qty
 * @property numeric-string|null $qty_approved
 * @property numeric-string $qty_returned_ok
 * @property numeric-string $qty_returned_damaged
 * @property numeric-string $qty_lost
 * @property string|null $condition_out
 * @property string|null $condition_out_note
 */
#[Fillable(['line_no', 'loan_asset_id', 'qty', 'remark'])]
class LoanLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'qty_approved' => 'decimal:6',
            'qty_returned_ok' => 'decimal:6',
            'qty_returned_damaged' => 'decimal:6',
            'qty_lost' => 'decimal:6',
        ];
    }

    /**
     * What was (or is to be) handed out on this line: the approved quantity (an APPROVED loan has it on every line).
     *
     * @return numeric-string
     */
    public function qtyOut(): string
    {
        return $this->qty_approved ?? $this->qty;
    }

    /**
     * Still with the borrower = handed out minus everything that came back or was written down as lost
     * (computed, never stored; spec section 5).
     *
     * @return numeric-string
     */
    public function outstanding(): string
    {
        $back = bcadd(bcadd($this->qty_returned_ok, $this->qty_returned_damaged, 6), $this->qty_lost, 6);

        return bcsub($this->qtyOut(), $back, 6);
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<LoanAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(LoanAsset::class, 'loan_asset_id');
    }
}
