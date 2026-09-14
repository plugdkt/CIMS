<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * NFR-02: tracks one queued F-03 ledger PDF export (see the create-table migration's
 * own doc comment for why this table exists).
 *
 * @property array{dateFrom: ?string, dateTo: ?string, txnType: ?string, receiverName: ?string, containerBarcode: ?string, labId?: ?int} $filter
 */
#[Fillable([
    'ulid', 'item_id', 'requested_by', 'display_unit_id', 'filter',
    'status', 'file_path', 'row_count', 'error_message',
])]
class LedgerExportRequest extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $export) {
            $export->ulid ??= (string) Str::ulid();
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
            'filter' => 'array',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Unit, $this> */
    public function displayUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'display_unit_id');
    }
}
