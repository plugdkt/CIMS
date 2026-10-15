<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * One purchased lot held in a branch's IMS store. `qty_*_base` are in the item's own
 * base unit (same convention as every other `_base` column — see CLAUDE.md).
 *
 * @property numeric-string $qty_received_base
 * @property numeric-string $qty_remaining_base
 * @property numeric-string|null $unit_price
 * @property numeric-string|null $pack_size_base
 * @property \Illuminate\Support\Carbon|null $expiry_date
 * @property \Illuminate\Support\Carbon $received_at
 */
#[Fillable([
    'ulid', 'lab_id', 'location_id', 'item_id', 'ims_receipt_id', 'doc_no', 'lot_no', 'fiscal_year',
    'purchase_round', 'unit_price', 'pack_size_base', 'expiry_date', 'qty_received_base', 'qty_remaining_base',
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
            'pack_size_base' => 'decimal:6',
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

    /**
     * How many packs (ขวด/แพ็ค) the remaining stock is — remaining ÷ the pack size the lot was
     * received with. Null for a lot with no recorded pack size. A part-used pack shows as a fraction.
     *
     * @return numeric-string|null
     */
    public function packsRemaining(): ?string
    {
        $size = $this->pack_size_base;
        if ($size === null || bccomp($size, '0', 6) <= 0) {
            return null;
        }

        return bcdiv($this->qty_remaining_base, $size, 2);
    }

    /** @return HasMany<ImsLotGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(ImsLotGroup::class)->orderBy('id');
    }

    /**
     * Remaining stock in packs at 4 dp (exact enough to compare against the placed groups). Null without a pack size.
     *
     * @return numeric-string|null
     */
    public function packsRemainingExact(): ?string
    {
        $size = $this->pack_size_base;
        if ($size === null || bccomp($size, '0', 6) <= 0) {
            return null;
        }

        return bcdiv($this->qty_remaining_base, $size, 4);
    }

    /** Packs received, for the "5 ขวด × 100 g" summary. Null without a pack size. */
    public function packsReceived(): ?string
    {
        $size = $this->pack_size_base;
        if ($size === null || bccomp($size, '0', 6) <= 0) {
            return null;
        }

        return bcdiv($this->qty_received_base, $size, 2);
    }

    /**
     * Remaining packs that no group claims — they use the lot's own location and expiry date.
     *
     * @return numeric-string
     */
    public function unplacedPacks(): string
    {
        $remaining = $this->packsRemainingExact();
        if ($remaining === null) {
            return '0.0000';
        }

        /** @var numeric-string $placed */
        $placed = $this->groups->reduce(fn (string $carry, ImsLotGroup $g) => bcadd($carry, $g->pack_count, 4), '0');
        $left = bcsub($remaining, $placed, 4);

        return bccomp($left, '0', 4) > 0 ? $left : '0.0000';
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Photos of the lot, newest first. Uploading never replaces — the newest one is the thumbnail.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner', 'owner_type', 'owner_id')
            ->where('doc_type', 'PHOTO')
            ->orderByDesc('id');
    }
}
