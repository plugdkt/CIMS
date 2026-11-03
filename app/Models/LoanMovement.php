<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ITM-2610-051: one row of the lending ledger. APPEND-ONLY: the database grants and triggers refuse
 * UPDATE/DELETE, and this model refuses them too so a mistake fails fast in development.
 *
 * @property \Illuminate\Support\Carbon $created_at
 * @property numeric-string $qty
 * @property numeric-string $qty_total_after
 * @property numeric-string $qty_on_loan_after
 * @property numeric-string $qty_unavailable_after
 * @property string $movement_type
 * @property int $loan_asset_id
 * @property int $created_by
 * @property int|null $approved_by
 */
#[Fillable([
    'loan_asset_id', 'lab_id', 'loan_id', 'loan_line_id', 'incident_id', 'movement_type', 'qty',
    'condition_recorded', 'qty_total_after', 'qty_on_loan_after', 'qty_unavailable_after',
    'note', 'external_ref', 'created_by', 'approved_by', 'created_at', 'prev_row_hash', 'row_hash',
])]
final class LoanMovement extends Model
{
    public $timestamps = false;

    /**
     * The hash includes created_at to the microsecond (`Y-m-d\TH:i:s.u`) and the column is DATETIME(6);
     * without this Eloquent would truncate to seconds on save and the recomputed hash would never match
     * (same lesson as StockLedger).
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('loan_movements is append-only'));
        static::deleting(fn () => throw new LogicException('loan_movements is append-only'));
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'qty_total_after' => 'decimal:6',
            'qty_on_loan_after' => 'decimal:6',
            'qty_unavailable_after' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LoanAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(LoanAsset::class, 'loan_asset_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
