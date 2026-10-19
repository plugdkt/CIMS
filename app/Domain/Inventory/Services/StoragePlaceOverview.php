<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\Container;
use App\Models\ImsLot;
use App\Models\ImsLotGroup;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * "Where is everything kept?" — read-only view over one branch's storage places: which places
 * exist, how many different substances each holds (its own shelves and everything beneath it),
 * and what exactly is in a place. Covers both the central store (IMS lots/groups of packs) and
 * working-stock bottles.
 */
final class StoragePlaceOverview
{
    /**
     * Every place of the branch with a substance count, plus how many IMS lots have no place yet.
     * With `$search`, only places holding a matching substance (or matching by name/code) remain,
     * and each carries the names of the matches so the answer to "where is X?" is on the card.
     *
     * @return array{places: list<array{location: Location, path: string, count: int, matches: list<string>, depth: int}>, unplaced: int}
     */
    public function index(int $labId, ?string $search): array
    {
        $locations = Location::where('lab_id', $labId)->orderBy('code')->get()->keyBy('id');

        // location id => [item id => item label] for what sits directly in that place.
        $direct = $this->directItems($labId);

        $places = [];
        foreach ($locations as $location) {
            $items = [];
            foreach ($this->subtreeIds($location->id, $locations) as $id) {
                foreach ($direct[$id] ?? [] as $itemId => $label) {
                    $items[$itemId] = $label;
                }
            }

            $matches = [];
            if ($search !== null && $search !== '') {
                $needle = mb_strtolower($search);
                $nameHit = str_contains(mb_strtolower($location->name.' '.$location->code), $needle);
                $matches = array_values(array_filter($items, fn (string $l) => str_contains(mb_strtolower($l), $needle)));
                if (! $nameHit && $matches === []) {
                    continue;
                }
            }

            $places[] = [
                'location' => $location,
                'path' => $this->path($location, $locations),
                'count' => count($items),
                'matches' => array_slice($matches, 0, 5),
                'depth' => substr_count($this->path($location, $locations), ' › '),
            ];
        }

        usort($places, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return [
            'places' => $places,
            'unplaced' => ImsLot::where('lab_id', $labId)->where('qty_remaining_base', '>', 0)->whereNull('location_id')->count(),
        ];
    }

    /**
     * What is kept in a place (and the places beneath it).
     *
     * @return array{path: string, ims: list<array<string, mixed>>, working: list<array<string, mixed>>}
     */
    public function contents(Location $place, ?string $search): array
    {
        $locations = Location::where('lab_id', $place->lab_id)->get()->keyBy('id');
        $ids = $this->subtreeIds($place->id, $locations);
        $needle = $search !== null && $search !== '' ? mb_strtolower($search) : null;

        $ims = [];
        $lots = ImsLot::with(['item.baseUnit', 'groups.location', 'location'])
            ->where('lab_id', $place->lab_id)
            ->where('qty_remaining_base', '>', 0)
            ->where(function ($q) use ($ids) {
                $q->whereIn('location_id', $ids)->orWhereHas('groups', fn ($g) => $g->whereIn('location_id', $ids));
            })
            ->get();

        foreach ($lots as $lot) {
            $item = $lot->item;
            if ($item === null || ($needle !== null && ! $this->matches($needle, $item->item_code, $item->name_th, $item->name_en))) {
                continue;
            }
            $size = $lot->pack_size_base;
            $hasSize = $size !== null && bccomp($size, '0', 6) > 0;

            /** @var ImsLotGroup $group */
            foreach ($lot->groups as $group) {
                if (! in_array($group->location_id, $ids, true)) {
                    continue;
                }
                $ims[] = $this->imsRow($lot, $locations, $group->location_id, $group->pack_count, $group->expiry_date?->format('d/m/Y'), $hasSize ? $size : null);
            }

            if ($lot->location_id !== null && in_array($lot->location_id, $ids, true)) {
                if ($hasSize && $lot->groups->isNotEmpty()) {
                    $left = $lot->unplacedPacks();
                    if (bccomp($left, '0', 4) > 0) {
                        $ims[] = $this->imsRow($lot, $locations, $lot->location_id, $left, $lot->expiry_date?->format('d/m/Y'), $size);
                    }
                } elseif ($hasSize) {
                    $ims[] = $this->imsRow($lot, $locations, $lot->location_id, $this->exactPacks($lot), $lot->expiry_date?->format('d/m/Y'), $size);
                } else {
                    $ims[] = $this->imsRow($lot, $locations, $lot->location_id, null, $lot->expiry_date?->format('d/m/Y'), null);
                }
            }
        }

        $working = [];
        $containers = Container::with(['item.baseUnit', 'location'])
            ->whereIn('location_id', $ids)
            ->whereIn('status', ['SEALED', 'IN_USE'])
            ->where('remaining_qty_base', '>', 0)
            ->orderBy('item_id')
            ->get();

        foreach ($containers as $container) {
            $item = $container->item;
            if ($item === null || ($needle !== null && ! $this->matches($needle, $item->item_code, $item->name_th, $item->name_en))) {
                continue;
            }
            $working[] = [
                'code' => $item->item_code,
                'name' => $item->name_th,
                'barcode' => $container->barcode,
                'qty' => $container->remaining_qty_base,
                'unit' => $item->baseUnit?->code,
                'expiry' => $container->expiry_date?->format('d/m/Y'),
                'place' => $this->path($container->location ?? $place, $locations),
                'status' => $container->status,
            ];
        }

        usort($ims, fn (array $a, array $b) => strcmp((string) $a['code'], (string) $b['code']));

        return ['path' => $this->path($place, $locations), 'ims' => $ims, 'working' => $working];
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  numeric-string|null  $packs
     * @param  numeric-string|null  $size
     * @return array<string, mixed>
     */
    private function imsRow(ImsLot $lot, Collection $locations, int $locationId, ?string $packs, ?string $expiry, ?string $size): array
    {
        $item = $lot->item;
        $place = $locations->get($locationId);

        return [
            'code' => $item?->item_code,
            'name' => $item?->name_th,
            'lot_no' => $lot->lot_no,
            'packs' => $packs,
            'word' => $item?->packagingWord(),
            'size' => $size,
            'qty' => $packs !== null && $size !== null ? bcmul($packs, $size, 6) : $lot->qty_remaining_base,
            'unit' => $item?->baseUnit?->code,
            'expiry' => $expiry,
            'place' => $place !== null ? $this->path($place, $locations) : null,
            'lot_ulid' => $lot->ulid,
        ];
    }

    /**
     * @return array<int, array<int, string>> location id => [item id => "code name"]
     */
    private function directItems(int $labId): array
    {
        $out = [];

        $add = function (?int $locationId, ?int $itemId, string $label) use (&$out): void {
            if ($locationId !== null && $itemId !== null) {
                $out[$locationId][$itemId] = $label;
            }
        };

        ImsLot::with('item:id,item_code,name_th')
            ->where('lab_id', $labId)->where('qty_remaining_base', '>', 0)->whereNotNull('location_id')
            ->get(['id', 'item_id', 'location_id'])
            ->each(fn (ImsLot $l) => $add($l->location_id, $l->item_id, $this->label($l->item)));

        ImsLotGroup::with('lot.item:id,item_code,name_th')
            ->whereNotNull('location_id')
            ->whereHas('lot', fn ($q) => $q->where('lab_id', $labId)->where('qty_remaining_base', '>', 0))
            ->get(['id', 'ims_lot_id', 'location_id'])
            ->each(fn (ImsLotGroup $g) => $add($g->location_id, $g->lot?->item_id, $this->label($g->lot?->item)));

        Container::with('item:id,item_code,name_th')
            ->whereIn('status', ['SEALED', 'IN_USE'])->where('remaining_qty_base', '>', 0)
            ->whereHas('location', fn ($q) => $q->where('lab_id', $labId))
            ->get(['id', 'item_id', 'location_id'])
            ->each(fn (Container $c) => $add($c->location_id, $c->item_id, $this->label($c->item)));

        return $out;
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @return list<int>
     */
    private function subtreeIds(int $rootId, Collection $locations): array
    {
        $ids = [$rootId];
        $queue = [$rootId];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($locations as $loc) {
                if ($loc->parent_id === $current && ! in_array($loc->id, $ids, true)) {
                    $ids[] = $loc->id;
                    $queue[] = $loc->id;
                }
            }
        }

        return $ids;
    }

    /** @param Collection<int, Location> $locations */
    private function path(Location $location, Collection $locations): string
    {
        $parts = [$location->name];
        $seen = [$location->id];
        $parentId = $location->parent_id;
        while ($parentId !== null && ! in_array($parentId, $seen, true)) {
            $parent = $locations->get($parentId);
            if ($parent === null) {
                break;
            }
            array_unshift($parts, $parent->name);
            $seen[] = $parent->id;
            $parentId = $parent->parent_id;
        }

        return implode(' › ', $parts);
    }

    private function label(?\App\Models\Item $item): string
    {
        return $item === null ? '' : $item->item_code.' '.$item->name_th;
    }

    /** @return numeric-string */
    private function exactPacks(ImsLot $lot): string
    {
        return $lot->packsRemainingExact() ?? '0';
    }

    private function matches(string $needle, ?string ...$haystacks): bool
    {
        foreach ($haystacks as $h) {
            if ($h !== null && str_contains(mb_strtolower($h), $needle)) {
                return true;
            }
        }

        return false;
    }
}
