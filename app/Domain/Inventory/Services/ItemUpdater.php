<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\ItemKindChangeBlockedException;
use App\Domain\Shared\ItemType;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place an existing item's details are changed — called by the items register (source `items.update`) and by
 * the IMS lot page (source `ims.lot`), so both apply the same guard and write the same audit trail.
 *
 *  - Guard (CEO 2026-11-05): moving an item to or from the science (loan-and-return) kind is refused while any
 *    unfinished requisition (anything not ISSUED / REJECTED / CANCELLED) still has a line for it, naming the documents.
 *    The item row is locked first and RequisitionService::addLine locks it too, so a line cannot slip in between.
 *  - Audit: one `ITEM_UPDATE` row with who, source and the before/after of every field that really changed.
 */
final class ItemUpdater
{
    public const SOURCE_REGISTER = 'items.update';

    public const SOURCE_IMS_LOT = 'ims.lot';

    /** Most document numbers named in the refusal message. */
    private const MAX_DOCS_LISTED = 5;

    /**
     * @param  array<string, mixed>  $data  validated fields (only keys the caller's FormRequest allows)
     * @param  array<string, mixed>  $context  extra facts for the audit row (e.g. the lot's ulid)
     *
     * @throws ItemKindChangeBlockedException
     */
    public function update(Item $item, array $data, User $actor, string $source, array $context = []): Item
    {
        return DB::transaction(function () use ($item, $data, $actor, $source, $context) {
            // Lock FIRST (before any plain read in this transaction) so the checks below see committed state.
            $locked = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $locked->load('category');
            $oldKind = ItemType::forItem($locked);

            if (isset($data['category_id']) && (int) $data['category_id'] !== (int) $locked->category_id) {
                $newCategory = ItemCategory::query()->findOrFail((int) $data['category_id']);
                $newKind = $newCategory->kind();
                if ($newKind !== $oldKind && ($newKind === ItemType::SCIENCE || $oldKind === ItemType::SCIENCE)) {
                    $this->assertNoUnfinishedRequisition($locked);
                }
            }

            $locked->fill($data);
            $dirty = $locked->getDirty();
            if ($dirty === []) {
                return $item->refresh();
            }

            $before = [];
            $after = [];
            foreach (array_keys($dirty) as $key) {
                $before[$key] = $locked->getRawOriginal($key);
                $after[$key] = $locked->getAttributes()[$key] ?? null;
            }
            $locked->save();

            AuditLog::record(
                action: 'ITEM_UPDATE',
                userId: $actor->id,
                username: $actor->username,
                entityType: 'item',
                entityId: $locked->id,
                oldValue: ['fields' => $before],
                newValue: ['fields' => $after, 'source' => $source] + $context,
                message: $source,
            );

            return $item->refresh();
        });
    }

    /** @throws ItemKindChangeBlockedException */
    private function assertNoUnfinishedRequisition(Item $item): void
    {
        $open = Requisition::query()
            ->open()
            ->whereHas('items', fn ($q) => $q->where('item_id', $item->id))
            ->orderBy('doc_no')
            ->pluck('doc_no')
            ->map(fn ($doc) => (string) $doc)
            ->values()
            ->all();

        if ($open === []) {
            return;
        }

        $shown = array_slice($open, 0, self::MAX_DOCS_LISTED);
        $more = count($open) - count($shown);
        $list = implode(', ', $shown).($more > 0 ? ' '.__('items.kind_change_more', ['count' => $more]) : '');

        throw new ItemKindChangeBlockedException((string) __('items.kind_change_blocked', ['docs' => $list]), array_values($open));
    }
}
