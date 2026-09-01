<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

#[Fillable([
    'item_code', 'category_id', 'name_th', 'name_en', 'cas_no', 'formula', 'brand', 'grade',
    'package_size', 'package_unit_id', 'sub_unit_id', 'base_unit_id', 'density_g_per_ml',
    'reorder_point_base', 'is_controlled', 'control_class', 'ghs_codes', 'h_statements',
    'p_statements', 'storage_class', 'shelf_life_days_after_open', 'is_active',
])]
class Item extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->ulid ??= (string) Str::ulid();
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
            'package_size' => 'decimal:6',
            'density_g_per_ml' => 'decimal:6',
            'reorder_point_base' => 'decimal:6',
            'is_controlled' => 'boolean',
            'is_active' => 'boolean',
            'ghs_codes' => 'array',
            'h_statements' => 'array',
            'p_statements' => 'array',
        ];
    }

    /** @return BelongsTo<ItemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function packageUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'package_unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function subUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'sub_unit_id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner');
    }
}
