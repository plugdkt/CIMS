<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ITM-2610-051 step 1: one lending asset (a UNIT item, or a POOL of identical items) owned by one branch.
 *
 * The quantity columns and `status` are deliberately NOT fillable: they only change through
 * LoanLedgerService (which writes the matching loan_movements row in the same transaction), never by
 * mass assignment from a request. `pool_key` is a generated database column.
 *
 * @property numeric-string $qty_total
 * @property numeric-string $qty_on_loan
 * @property numeric-string $qty_unavailable
 * @property numeric-string|null $replacement_value
 * @property string $status
 * @property string $tracking_mode
 * @property int $lab_id
 * @property int $item_id
 * @property int|null $location_id
 */
#[Fillable([
    'lab_id', 'item_id', 'tracking_mode', 'asset_tag', 'location_id',
    'max_loan_days', 'replacement_value', 'condition_note',
])]
class LoanAsset extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $asset) {
            $asset->ulid ??= (string) Str::ulid();
        });
    }

    /** AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'qty_total' => 'decimal:6',
            'qty_on_loan' => 'decimal:6',
            'qty_unavailable' => 'decimal:6',
            'replacement_value' => 'decimal:2',
        ];
    }

    /**
     * What can still be lent: total minus what is out and what is unusable (BR-L02's "available").
     *
     * @return numeric-string
     */
    public function available(): string
    {
        return bcsub(bcsub($this->qty_total, $this->qty_on_loan, 6), $this->qty_unavailable, 6);
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<LoanMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(LoanMovement::class)->orderBy('id');
    }
}
