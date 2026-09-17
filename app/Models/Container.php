<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property numeric-string $initial_qty_base
 * @property numeric-string $remaining_qty_base
 * @property numeric-string|null $unit_price
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon|null $opened_at
 */
#[Fillable([
    'ulid', 'barcode', 'item_id', 'location_id', 'lot_no', 'received_at', 'expiry_date',
    'opened_at', 'initial_qty_base', 'remaining_qty_base', 'unit_price', 'status',
])]
class Container extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $container) {
            $container->ulid ??= (string) Str::ulid();
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
            'received_at' => 'date',
            'expiry_date' => 'date',
            'opened_at' => 'date',
            'initial_qty_base' => 'decimal:6',
            'remaining_qty_base' => 'decimal:6',
            'unit_price' => 'decimal:2',
        ];
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

    /** @return HasMany<StockLedger, $this> */
    public function stockLedgerRows(): HasMany
    {
        return $this->hasMany(StockLedger::class, 'container_id');
    }

    /**
     * `location_id`/`locations.lab_id` are both nullable, so this genuinely can be empty
     * — written as an explicit `if` (not `?->`/`??`) since PHPStan's nullsafe inference
     * for chained relation access is unreliable in either direction (see CLAUDE.md).
     */
    public function labNameOrEmpty(): string
    {
        $location = $this->location()->first();
        if ($location === null) {
            return '';
        }

        $lab = $location->lab()->first();

        return $lab === null ? '' : $lab->name_th;
    }
}
