<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['ulid', 'code', 'name_th', 'faculty', 'is_active'])]
class Lab extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $lab) {
            $lab->ulid ??= (string) Str::ulid();
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
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
