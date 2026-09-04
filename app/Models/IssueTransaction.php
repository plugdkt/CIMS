<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property numeric-string $qty_issued_base
 * @property \Illuminate\Support\Carbon $issued_at
 */
#[Fillable([
    'ulid', 'requisition_item_id', 'container_id', 'qty_issued_base', 'issued_at',
    'issuer_id', 'receiver_id', 'signature_hash', 'signature_image_path', 'remark',
])]
class IssueTransaction extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $issueTransaction) {
            $issueTransaction->ulid ??= (string) Str::ulid();
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
            'qty_issued_base' => 'decimal:6',
            'issued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RequisitionItem, $this> */
    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class);
    }

    /** @return BelongsTo<Container, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
