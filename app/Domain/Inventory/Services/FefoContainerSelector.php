<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\Container;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * BR-03: recommends which container to issue from — a recommendation only, the issuer may
 * pick any other container instead (the caller must then require a remark; that's a UI/
 * FormRequest concern, not this class's).
 *
 * Ranking: `IN_USE` before `SEALED` (empty/disposed/quarantined containers are never
 * eligible), then soonest `expiry_date` first within each status, then — spec's literal
 * fallback — oldest `received_at` first when `expiry_date` is NULL for both being compared.
 * Spec doesn't say where a NULL-expiry container ranks against one with a known expiry;
 * read literally FEFO ("first-expired-first-out"), a container that *will* expire is more
 * urgent to use than one that never does, so known-expiry containers sort before NULL-
 * expiry ones within the same status tier (documented in CLAUDE.md as an inferred
 * convention, since spec only spells out the both-NULL case).
 */
final class FefoContainerSelector
{
    private const ELIGIBLE_STATUSES = ['IN_USE', 'SEALED'];

    /** @return Collection<int, Container> */
    public function recommend(Item $item): Collection
    {
        return Container::where('item_id', $item->id)
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->where('remaining_qty_base', '>', 0)
            ->get()
            ->sort(fn (Container $a, Container $b) => $this->compare($a, $b))
            ->values();
    }

    private function compare(Container $a, Container $b): int
    {
        $statusRank = fn (Container $c) => $c->status === 'IN_USE' ? 0 : 1;
        if (($cmp = $statusRank($a) <=> $statusRank($b)) !== 0) {
            return $cmp;
        }

        $hasNoExpiry = fn (Container $c) => $c->expiry_date === null ? 1 : 0;
        if (($cmp = $hasNoExpiry($a) <=> $hasNoExpiry($b)) !== 0) {
            return $cmp;
        }

        if ($a->expiry_date !== null && $b->expiry_date !== null && ($cmp = $a->expiry_date->timestamp <=> $b->expiry_date->timestamp) !== 0) {
            return $cmp;
        }

        return $a->received_at->timestamp <=> $b->received_at->timestamp;
    }

    /** BR-03: never recommend an already-expired container — a manual pick still can, with a red warning. */
    public function isExpired(Container $container): bool
    {
        return $container->expiry_date !== null && $container->expiry_date->isPast();
    }

    /** @return Collection<int, Container> */
    public function recommendExcludingExpired(Item $item): Collection
    {
        return $this->recommend($item)->reject(fn (Container $c) => $this->isExpired($c))->values();
    }
}
