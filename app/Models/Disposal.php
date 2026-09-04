<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property numeric-string $qty_base
 * @property \Illuminate\Support\Carbon $disposal_date
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $approved_by
 */
#[Fillable([
    'ulid', 'doc_no', 'container_id', 'qty_base', 'reason', 'method', 'disposal_date',
    'requested_by', 'approved_by', 'approved_at', 'status',
])]
class Disposal extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $disposal) {
            $disposal->ulid ??= (string) Str::ulid();
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
            'qty_base' => 'decimal:6',
            'disposal_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Container, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
