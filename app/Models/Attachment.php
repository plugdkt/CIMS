<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * @property \Illuminate\Support\Carbon|null $created_at
 */
#[Fillable([
    'ulid', 'owner_type', 'owner_id', 'doc_type', 'original_name', 'stored_name',
    'mime_type', 'size_bytes', 'sha256', 'version', 'revised_date', 'uploaded_by',
])]
class Attachment extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $attachment) {
            $attachment->ulid ??= (string) Str::ulid();
            $attachment->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'revised_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /** AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'owner_type', 'owner_id');
    }
}
