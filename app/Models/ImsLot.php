<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One purchased lot held in a branch's IMS store. `qty_*_base` are in the item's own
 * base unit (same convention as every other `_base` column — see CLAUDE.md).
 *
 * @property numeric-string $qty_received_base
 * @property numeric-string $qty_remaining_base
 * @property numeric-string|null $unit_price
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon $received_at
 */
#[Fillable([
    'ulid', 'lab_id', 'item_id', 'ims_receipt_id', 'doc_no', 'lot_no', 'fiscal_year',
    'purchase_round', 'unit_price', 'expiry_date', 'qty_received_base', 'qty_remaining_base',
    'received_at',
])]
class ImsLot extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $lot) {
            $lot->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'expiry_date' => 'date',
            'received_at' => 'date',
            'qty_received_base' => 'decimal:6',
            'qty_remaining_base' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return HasMany<ImsMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(ImsMovement::class);
    }

    /** @return HasMany<Container, $this> */
    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }
}
