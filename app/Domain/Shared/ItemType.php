<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use App\Models\Item;
use App\Models\ItemCategory;

/**
 * The kinds of stock, as they appear in URLs (`?type=chemical|consumable`) and in `user_item_types.item_type`.
 *
 *  - chemical, consumable: the two kinds a REQUISITION can be about ({@see self::requisitionKinds()});
 *  - science: loan-and-return science materials (CEO 2026-11-05) — stocked and looked after by a keeper like the
 *    others, but never requisitionable: they are lent (loan module), not issued.
 *
 * {@see self::all()} = every kind of STOCK (keeper assignments, IMS, reports). A catalog item's kind comes from its
 * category (see {@see ItemCategory}) — never from what a requester ticked on a form.
 */
final class ItemType
{
    public const CHEMICAL = 'chemical';

    public const CONSUMABLE = 'consumable';

    public const SCIENCE = 'science';

    /**
     * Every kind of stock (who may look after which kind, stock lists, reports).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CHEMICAL, self::CONSUMABLE, self::SCIENCE];
    }

    /**
     * The kinds a requisition / shop basket can hold. Science (loan) is deliberately not here.
     *
     * @return list<string>
     */
    public static function requisitionKinds(): array
    {
        return [self::CHEMICAL, self::CONSUMABLE];
    }

    /** Any kind of stock. */
    public static function isValid(?string $slug): bool
    {
        return in_array($slug, self::all(), true);
    }

    /** A kind that can be requisitioned (chemical or consumable). */
    public static function isRequisitionKind(?string $slug): bool
    {
        return in_array($slug, self::requisitionKinds(), true);
    }

    public static function isChemical(string $slug): bool
    {
        return $slug === self::CHEMICAL;
    }

    /** Thai label of a kind (lang `items.kind_*`). */
    public static function label(string $slug): string
    {
        return (string) __('items.kind_'.$slug);
    }

    /** Old two-way mapping, kept for callers that only know the `is_chemical` flag (never yields science). */
    public static function slugFor(bool $isChemical): string
    {
        return $isChemical ? self::CHEMICAL : self::CONSUMABLE;
    }

    /** The kind of a category from its two columns (the one rule; also used by raw-query callers). A missing category counts as chemical. */
    public static function slugForCategory(?bool $isChemical, ?string $code): string
    {
        if ($isChemical === null || $isChemical) {
            return self::CHEMICAL;
        }

        return $code === ItemCategory::LOAN_CODE ? self::SCIENCE : self::CONSUMABLE;
    }

    /** The kind of one catalog item, from its category (a missing category counts as chemical, as the default). */
    public static function forItem(Item $item): string
    {
        $category = $item->category;

        return $category === null ? self::CHEMICAL : $category->kind();
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
