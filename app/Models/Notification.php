<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['ulid', 'user_id', 'type', 'title', 'body', 'link_url', 'read_at', 'created_at'])]
class Notification extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            $notification->ulid ??= (string) Str::ulid();
            $notification->created_at ??= now();
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
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
