<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
#[Fillable([
    'ulid', 'doc_no', 'receipt_date', 'po_no', 'invoice_no', 'supplier', 'lab_id',
    'status', 'received_by', 'confirmed_at', 'remark',
])]
class GoodsReceipt extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $goodsReceipt) {
            $goodsReceipt->ulid ??= (string) Str::ulid();
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
            'receipt_date' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('line_no');
    }
}
