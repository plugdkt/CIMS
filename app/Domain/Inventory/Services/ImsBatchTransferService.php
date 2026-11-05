<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\ImsException;
use App\Models\AuditLog;
use App\Models\ImsLot;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ITM-2610-062: one paper requisition from the university store = one call here. Every row is moved from IMS
 * into working stock through the existing {@see ImsService::transferToWorkingStock()}, all inside ONE transaction
 * (a bad row rolls the whole document back), lots locked in id order so two managers cannot deadlock.
 *
 * Quantities are in each lot's own base unit (BCMath scale 6, never float); the page takes WHOLE packs, so a lot with a
 * pack size becomes one container per pack (each physical bottle gets a label). A part-pack remainder is still handled
 * defensively (one extra container) should another caller pass one; a lot without a pack size is cut as one bulk quantity.
 */
final class ImsBatchTransferService
{
    private const SCALE = 6;

    public const AUDIT_ACTION = 'IMS_TRANSFER_BATCH';

    /** Most containers (one per pack) one paper document may create — the single place to change the ceiling. */
    public const MAX_CONTAINERS_PER_DOC = 500;

    /**
     * How many containers moving `$qtyBase` of this lot creates: one per whole pack (plus one for a part-pack,
     * which the form never sends); a lot without a pack size is one bulk container.
     *
     * @param  numeric-string  $qtyBase
     */
    public static function containerCount(ImsLot $lot, string $qtyBase): int
    {
        $pack = $lot->pack_size_base;
        if ($pack === null || bccomp($pack, '0', self::SCALE) <= 0) {
            return 1;
        }

        $packs = (int) bcdiv($qtyBase, $pack, 0);
        $rest = bcsub($qtyBase, bcmul((string) $packs, $pack, self::SCALE), self::SCALE);

        return $packs + (bccomp($rest, '0', self::SCALE) > 0 ? 1 : 0);
    }

    public function __construct(private readonly ImsService $ims)
    {
    }

    /**
     * @param  list<array{lot: ImsLot, packs?: string, qty: numeric-string, location: Location}>  $rows  qty = base units (whole packs x pack size)
     * @param  string  $requestKey  identifies this one form submission; a second submission with the same key is refused
     * @return array{container_ids: list<int>, row_count: int}
     *
     * @throws ImsException  a row cannot be moved (message already prefixed with the row number), or the form was already saved
     * @throws AuthorizationException  a row is a lot the user may not move (tampered form)
     */
    public function transfer(User $user, string $docNo, ?string $remark, array $rows, string $requestKey): array
    {
        return DB::transaction(function () use ($user, $docNo, $remark, $rows, $requestKey) {
            $ids = array_values(array_unique(array_map(fn (array $r) => $r['lot']->id, $rows)));
            sort($ids);

            // Lock in id order (CLAUDE: avoids deadlock between two documents touching the same lots).
            $locked = ImsLot::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Checked after the locks are held: a concurrent identical submission waits here, then sees the
            // first one's audit row and stops, so a double click can never move the stock twice.
            if (AuditLog::query()->where('action', self::AUDIT_ACTION)->where('new_value->request', $requestKey)->exists()) {
                throw new ImsException(__('ims_transfer.error.already_submitted'));
            }

            $containerIds = [];
            $summary = [];

            // Containers this document would create (same ceiling as the form request; re-checked here).
            $total = 0;
            foreach ($rows as $row) {
                $total += self::containerCount($locked->get($row['lot']->id) ?? $row['lot'], $row['qty']);
            }
            if ($total > self::MAX_CONTAINERS_PER_DOC) {
                throw new ImsException(__('ims_transfer.error.too_many_containers', ['max' => self::MAX_CONTAINERS_PER_DOC, 'count' => $total]));
            }

            // Process in item id then lot id order (same order every document, so two documents cannot deadlock
            // on the rows the nested service locks); row numbers in messages stay as the user typed them.
            $order = array_keys($rows);
            usort($order, function (int $a, int $b) use ($rows, $locked): int {
                $la = $locked->get($rows[$a]['lot']->id) ?? $rows[$a]['lot'];
                $lb = $locked->get($rows[$b]['lot']->id) ?? $rows[$b]['lot'];

                return [$la->item_id, $la->id, $a] <=> [$lb->item_id, $lb->id, $b];
            });

            foreach ($order as $index) {
                $row = $rows[$index];
                $number = $index + 1;
                $lot = $locked->get($row['lot']->id);
                if ($lot === null) {
                    throw new ImsException(__('ims_transfer.error.row', ['row' => $number, 'message' => __('ims_transfer.error.lot_not_found')]));
                }

                $this->assertAllowed($user, $lot, $number);

                $item = $lot->item()->with(['baseUnit', 'category'])->firstOrFail();
                $unit = $item->baseUnit;
                if ($unit === null) {
                    throw new ImsException(__('ims_transfer.error.row', ['row' => $number, 'message' => __('ims.error.no_base_unit')]));
                }

                try {
                    foreach ($this->plan($lot, $row['qty']) as $part) {
                        $containers = $this->ims->transferToWorkingStock(
                            $lot,
                            $user,
                            $unit,
                            $row['location'],
                            $part['tracking'],
                            $part['qty'],
                            $part['count'],
                            $part['per'],
                            $docNo,
                            $remark,
                        );
                        foreach ($containers as $container) {
                            $containerIds[] = $container->id;
                        }
                    }
                } catch (ImsException $e) {
                    throw new ImsException(__('ims_transfer.error.row', ['row' => $number, 'message' => $e->getMessage()]), 0, $e);
                }

                $summary[] = ['lot' => $lot->ulid, 'lot_no' => $lot->lot_no, 'qty_base' => $row['qty']];
            }

            AuditLog::record(
                action: self::AUDIT_ACTION,
                userId: $user->id,
                username: $user->username,
                entityType: 'ims_transfer',
                newValue: [
                    'request' => $requestKey,
                    'doc_no' => $docNo,
                    'row_count' => count($rows),
                    'lots' => $summary,
                ],
                message: __('ims_transfer.audit_message', ['doc' => $docNo, 'count' => count($rows)]),
            );

            return ['container_ids' => $containerIds, 'row_count' => count($rows)];
        }, 3);
    }

    /**
     * Same rule as the policy, checked again on the locked row. A lot somebody else emptied while the form was open
     * is an ordinary "not enough stock" message; anything else is a forbidden row.
     */
    private function assertAllowed(User $user, ImsLot $lot, int $number): void
    {
        if (Gate::forUser($user)->allows('transfer', $lot)) {
            return;
        }

        $emptied = bccomp($lot->qty_remaining_base, '0', self::SCALE) <= 0 && $user->lab_id === $lot->lab_id;
        if ($emptied) {
            throw new ImsException(__('ims_transfer.error.row', ['row' => $number, 'message' => __('ims_transfer.error.lot_empty')]));
        }

        throw new AuthorizationException();
    }

    /**
     * @param  numeric-string  $qty
     * @return list<array{tracking: string, qty: numeric-string|null, count: int|null, per: numeric-string|null}>
     */
    private function plan(ImsLot $lot, string $qty): array
    {
        if (self::containerCount($lot, $qty) > self::MAX_CONTAINERS_PER_DOC) {
            throw new ImsException(__('ims_transfer.error.too_many_containers', ['max' => self::MAX_CONTAINERS_PER_DOC, 'count' => self::containerCount($lot, $qty)]));
        }

        $pack = $lot->pack_size_base;
        if ($pack === null || bccomp($pack, '0', self::SCALE) <= 0) {
            return [['tracking' => 'BULK', 'qty' => $qty, 'count' => null, 'per' => null]];
        }

        $packs = (int) bcdiv($qty, $pack, 0);
        /** @var numeric-string $rest */
        $rest = bcsub($qty, bcmul((string) $packs, $pack, self::SCALE), self::SCALE);

        $parts = [];
        if ($packs > 0) {
            $parts[] = ['tracking' => 'CONTAINER', 'qty' => null, 'count' => $packs, 'per' => $pack];
        }
        if (bccomp($rest, '0', self::SCALE) > 0) {
            $parts[] = ['tracking' => 'CONTAINER', 'qty' => null, 'count' => 1, 'per' => $rest];
        }

        return $parts;
    }
}
