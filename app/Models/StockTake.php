<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property \Illuminate\Support\Carbon $count_date
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $approved_by
 */
#[Fillable(['ulid', 'doc_no', 'lab_id', 'count_date', 'status', 'created_by', 'approved_by', 'approved_at'])]
class StockTake extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $stockTake) {
            $stockTake->ulid ??= (string) Str::ulid();
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
            'count_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<StockTakeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTakeLine::class);
    }
}
