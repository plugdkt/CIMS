<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One purchase/receiving document printed from the university's own warehouse system.
 * DRAFT while a warehouse manager is still checking the lines; CONFIRMED once they
 * accept it, at which point each line becomes an {@see ImsLot}.
 *
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
#[Fillable([
    'ulid', 'lab_id', 'doc_no', 'fiscal_year', 'purchase_round', 'source_file_path',
    'source_file_name', 'status', 'created_by', 'confirmed_by', 'confirmed_at',
])]
class ImsReceipt extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $receipt->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ImsReceiptLine::class)->orderBy('line_no');
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }
}
