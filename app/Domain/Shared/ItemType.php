<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\Item;

/**
 * ITM-2610-052: the two kinds of stock a requisition is about, as they appear in URLs
 * (`?type=chemical|consumable`). A catalog item's kind comes from its category's
 * `is_chemical` flag (see ItemCategorySeeder) — never from what a requester ticked on a
 * form — so "consumable" simply means every non-chemical category.
 */
final class ItemType
{
    public const CHEMICAL = 'chemical';

    public const CONSUMABLE = 'consumable';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::CHEMICAL, self::CONSUMABLE];
    }

    public static function isValid(?string $slug): bool
    {
        return in_array($slug, self::all(), true);
    }

    public static function isChemical(string $slug): bool
    {
        return $slug === self::CHEMICAL;
    }

    public static function slugFor(bool $isChemical): string
    {
        return $isChemical ? self::CHEMICAL : self::CONSUMABLE;
    }

    /** The kind of one catalog item, from its category's `is_chemical` flag (a missing category counts as chemical, as the default). */
    public static function forItem(Item $item): string
    {
        $category = $item->category;

        return self::slugFor($category === null || $category->is_chemical);
    }

    /**
     * The value stored in `requisitions.request_type` (a MySQL SET kept for history and old
     * documents) for a kind. New requisitions always get exactly this one value.
     */
    public static function requestTypeValue(string $slug): string
    {
        return self::isChemical($slug) ? 'CHEMICAL' : 'CONSUMABLE';
    }

    /** Session key of the shop cart for one kind — each kind has its own basket. */
    public static function cartKey(string $slug): string
    {
        return 'shop.cart.'.$slug;
    }
}
