<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A group of packs of one lot that share a storage place and expiry date.
 *
 * @property numeric-string $pack_count
 * @property \Illuminate\Support\Carbon|null $expiry_date
 */
#[Fillable(['ulid', 'ims_lot_id', 'pack_count', 'location_id', 'expiry_date', 'remark'])]
class ImsLotGroup extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $group) {
            $group->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'pack_count' => 'decimal:4',
            'expiry_date' => 'date',
        ];
    }

    /** AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<ImsLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ImsLot::class, 'ims_lot_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
