<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\ItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalog category. The KIND of stock of every item in it (chemical / consumable / science-loan) is derived from the
 * category — there is no column on the item. `is_chemical` is a boolean, so the third kind is a dedicated category
 * (code {@see self::LOAN_CODE}, is_chemical = false) instead of a new column (CEO 2026-11-05: no migration).
 * Everything that needs "categories of kind X" goes through {@see self::scopeOfKind()} / {@see self::kind()}.
 */
#[Fillable(['code', 'name_th', 'is_chemical'])]
class ItemCategory extends Model
{
    /** The category of loan-and-return science materials (วัสดุวิทยาศาสตร์ ยืม-คืน). */
    public const LOAN_CODE = 'LOAN_SCIENCE';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_chemical' => 'boolean',
        ];
    }

    /** The {@see ItemType} slug of the items in this category. */
    public function kind(): string
    {
        return ItemType::slugForCategory((bool) $this->is_chemical, (string) $this->code);
    }

    /**
     * Categories whose items can be requisitioned (chemical + consumable; never science/loan).
     *
     * @param  Builder<ItemCategory>  $query
     * @return Builder<ItemCategory>
     */
    public function scopeRequisitionable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_chemical', true)->orWhere(fn (Builder $c) => self::constrainKind($c, ItemType::CONSUMABLE)));
    }

    /**
     * Categories of one kind (an {@see ItemType} slug).
     *
     * @param  Builder<ItemCategory>  $query
     * @return Builder<ItemCategory>
     */
    public function scopeOfKind(Builder $query, string $slug): Builder
    {
        return self::constrainKind($query, $slug);
    }

    /**
     * Same constraint as {@see scopeOfKind()}, callable on the untyped builder inside whereHas() closures.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrainKind(Builder $query, string $slug): Builder
    {
        return match ($slug) {
            ItemType::CHEMICAL => $query->where('is_chemical', true),
            ItemType::SCIENCE => $query->where('is_chemical', false)->where('code', self::LOAN_CODE),
            // consumable: every other non-chemical category
            default => $query->where('is_chemical', false)->where(fn (Builder $q) => $q->whereNull('code')->orWhere('code', '!=', self::LOAN_CODE)),
        };
    }
}
