<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ItemType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-062: READ-ONLY report to run before the cut-over (shop/issue count working stock only). It never writes and
 * never changes a quantity; the warehouse managers decide item by item through the existing adjustment menu.
 *   1. per item and branch: IMS remaining vs working-stock containers (only places that belong to a branch);
 *   2. containers with no place / a place of no branch / a branch different from their source lot's;
 *   3. APPROVED / PARTIALLY_ISSUED requisition lines that working stock cannot cover but IMS could (per branch and kind);
 *   4. kinds with no manager or more than one in a branch (warning only, design A+).
 * Quantities are DECIMAL strings compared with BCMath (scale 6), never float.
 */
final class ImsReconcileWorkingStockCommand extends Command
{
    private const SCALE = 6;

    protected $signature = 'ims:reconcile-working-stock {--lab= : Only this branch (lab code or id)}';

    protected $description = 'รายงานเทียบยอด IMS กับคลังใช้งาน ใบเบิกค้าง และผู้ดูแลคลัง (อ่านอย่างเดียว ไม่แก้ข้อมูล)';

    public function handle(): int
    {
        foreach (['labs', 'ims_lots', 'containers', 'locations', 'requisitions', 'requisition_items', 'items', 'user_item_types'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->info((string) __('ims_reconcile.no_tables'));

                return self::SUCCESS;
            }
        }

        $labs = DB::table('labs')->orderBy('id');
        $option = $this->option('lab');
        if (is_string($option) && $option !== '') {
            $labs->where(fn ($q) => $q->where('code', $option)->orWhere('id', ctype_digit($option) ? (int) $option : 0));
        }
        $rows = $labs->get(['id', 'code', 'name_th']);
        if ($rows->isEmpty()) {
            $this->error((string) __('ims_reconcile.lab_not_found', ['lab' => (string) $option]));

            return self::FAILURE;
        }

        $this->line((string) __('ims_reconcile.readonly_note'));
        foreach ($rows as $lab) {
            $this->report((int) $lab->id, $lab->code.' '.$lab->name_th);
        }
        $this->info((string) __('ims_reconcile.done'));

        return self::SUCCESS;
    }

    private function report(int $labId, string $label): void
    {
        $this->newLine();
        $this->info((string) __('ims_reconcile.header', ['lab' => $label]));

        $ims = $this->imsByItem($labId);
        $working = $this->workingByItem($labId);

        $this->compare($ims, $working);
        $this->oddContainers($labId);
        $this->openRequisitions($labId, $ims, $working);
        $this->managers($labId, $ims !== []);
    }

    /** @return array<int, numeric-string> item id => IMS remaining */
    private function imsByItem(int $labId): array
    {
        $out = [];
        // Science (loan-and-return) materials are not moved into working stock (they are lent), so they are left out.
        $lots = DB::table('ims_lots')
            ->join('items', 'items.id', '=', 'ims_lots.item_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'items.category_id')
            ->where('ims_lots.lab_id', $labId)
            ->where('ims_lots.qty_remaining_base', '>', 0)
            ->get(['ims_lots.item_id', 'ims_lots.qty_remaining_base', 'item_categories.is_chemical', 'item_categories.code']);
        foreach ($lots as $lot) {
            if (ItemType::slugForCategory($lot->is_chemical === null ? null : (bool) $lot->is_chemical, $lot->code === null ? null : (string) $lot->code) === ItemType::SCIENCE) {
                continue;
            }
            $id = (int) $lot->item_id;
            /** @var numeric-string $qty */
            $qty = (string) $lot->qty_remaining_base;
            $out[$id] = bcadd($out[$id] ?? '0', $qty, self::SCALE);
        }

        return $out;
    }

    /** @return array<int, numeric-string> item id => remaining in containers whose place belongs to this branch */
    private function workingByItem(int $labId): array
    {
        $out = [];
        $query = DB::table('containers')
            ->join('locations', 'locations.id', '=', 'containers.location_id')
            ->where('locations.lab_id', $labId)
            ->whereIn('containers.status', ['SEALED', 'IN_USE'])
            ->where('containers.remaining_qty_base', '>', 0)
            ->select('containers.item_id', 'containers.remaining_qty_base');
        foreach ($query->get() as $c) {
            $id = (int) $c->item_id;
            /** @var numeric-string $qty */
            $qty = (string) $c->remaining_qty_base;
            $out[$id] = bcadd($out[$id] ?? '0', $qty, self::SCALE);
        }

        return $out;
    }

    /**
     * @param  array<int, numeric-string>  $ims
     * @param  array<int, numeric-string>  $working
     */
    private function compare(array $ims, array $working): void
    {
        $this->line((string) __('ims_reconcile.sec_compare'));
        if ($ims === []) {
            $this->line('  '.__('ims_reconcile.compare_none'));

            return;
        }

        $items = DB::table('items')->whereIn('id', array_keys($ims))->get(['id', 'item_code', 'name_th'])->keyBy('id');
        $table = [];
        foreach ($ims as $itemId => $qty) {
            $w = $working[$itemId] ?? '0';
            $item = $items->get($itemId);
            $table[] = [
                $item->item_code ?? '#'.$itemId,
                mb_substr((string) ($item->name_th ?? ''), 0, 40),
                $qty,
                $w,
                bccomp($w, '0', self::SCALE) > 0 ? __('ims_reconcile.flag_both') : __('ims_reconcile.flag_ims_only'),
            ];
        }
        usort($table, fn (array $a, array $b) => strcmp((string) $a[0], (string) $b[0]));

        $this->table([
            (string) __('ims_reconcile.col_code'),
            (string) __('ims_reconcile.col_name'),
            (string) __('ims_reconcile.col_ims'),
            (string) __('ims_reconcile.col_working'),
            (string) __('ims_reconcile.col_flag'),
        ], $table);
    }

    /** Containers with no place, a place of no branch, or a branch other than the lot they were cut from. */
    private function oddContainers(int $labId): void
    {
        $this->line((string) __('ims_reconcile.sec_containers'));

        $base = DB::table('containers')
            ->leftJoin('locations', 'locations.id', '=', 'containers.location_id')
            ->leftJoin('ims_lots', 'ims_lots.id', '=', 'containers.ims_lot_id')
            ->whereIn('containers.status', ['SEALED', 'IN_USE'])
            ->where('containers.remaining_qty_base', '>', 0)
            ->where(function ($q) use ($labId) {
                // This branch's stock only: its own place, or (no place at all) a lot of this branch.
                $q->where('locations.lab_id', $labId)
                    ->orWhere(fn ($n) => $n->whereNull('locations.lab_id')->where('ims_lots.lab_id', $labId));
            })
            ->where(function ($q) {
                $q->whereNull('containers.location_id')
                    ->orWhereNull('locations.lab_id')
                    ->orWhereColumn('locations.lab_id', '!=', 'ims_lots.lab_id');
            });

        $count = (clone $base)->count();
        if ($count === 0) {
            $this->line('  '.__('ims_reconcile.containers_none'));

            return;
        }

        $sample = (clone $base)->orderBy('containers.id')->limit(10)->pluck('containers.barcode')->implode(', ');
        $this->line('  '.__('ims_reconcile.containers_count', ['count' => $count, 'sample' => $sample]));
    }

    /**
     * @param  array<int, numeric-string>  $ims
     * @param  array<int, numeric-string>  $working
     */
    private function openRequisitions(int $labId, array $ims, array $working): void
    {
        $this->line((string) __('ims_reconcile.sec_requisitions'));

        /** @var array<string, array{docs: array<int, true>, lines: int}> $byKind */
        $byKind = [];

        $lines = DB::table('requisition_items')
            ->join('requisitions', 'requisitions.id', '=', 'requisition_items.requisition_id')
            ->join('items', 'items.id', '=', 'requisition_items.item_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'items.category_id')
            ->where('requisitions.lab_id', $labId)
            ->whereIn('requisitions.status', ['APPROVED', 'PARTIALLY_ISSUED'])
            ->select([
                'requisition_items.requisition_id', 'requisition_items.item_id',
                'requisition_items.qty_requested_base', 'requisition_items.qty_approved_base', 'requisition_items.qty_issued_base',
                'item_categories.is_chemical', 'item_categories.code',
            ])
            ->orderBy('requisition_items.id')
            ->get();

        foreach ($lines as $line) {
            /** @var numeric-string $ceiling */
            $ceiling = (string) ($line->qty_approved_base ?? $line->qty_requested_base);
            /** @var numeric-string $issued */
            $issued = (string) $line->qty_issued_base;
            $left = bcsub($ceiling, $issued, self::SCALE);
            if (bccomp($left, '0', self::SCALE) <= 0) {
                continue;
            }

            // Same rule as ItemType::forItem(): no category counts as chemical. A science (loan) line cannot be
            // requisitioned any more, so it is reported under its own kind row rather than failing.
            $kind = ItemType::slugForCategory($line->is_chemical === null ? null : (bool) $line->is_chemical, $line->code === null ? null : (string) $line->code);

            // Science is not moved from IMS (it is lent), so there is no IMS/working comparison: every open line
            // with something left to issue is counted. Other kinds: only lines working stock cannot cover but IMS could.
            if ($kind !== ItemType::SCIENCE) {
                $itemId = (int) $line->item_id;
                $w = $working[$itemId] ?? '0';
                $i = $ims[$itemId] ?? '0';
                if (bccomp($w, $left, self::SCALE) >= 0 || bccomp($i, '0', self::SCALE) <= 0) {
                    continue;
                }
            }

            $byKind[$kind]['docs'][(int) $line->requisition_id] = true;
            $byKind[$kind]['lines'] = ($byKind[$kind]['lines'] ?? 0) + 1;
        }

        if ($byKind === []) {
            $this->line('  '.__('ims_reconcile.requisitions_none'));

            return;
        }

        $table = [];
        foreach ($byKind as $kind => $data) {
            $table[] = [(string) __('ims_reconcile.kind_'.$kind), count($data['docs']), $data['lines']];
        }
        $this->table([
            (string) __('ims_reconcile.col_kind'),
            (string) __('ims_reconcile.col_docs'),
            (string) __('ims_reconcile.col_lines'),
        ], $table);
    }

    /** Active managers per kind in the branch (ADMIN is not counted). Only shown for branches that hold IMS stock. */
    private function managers(int $labId, bool $hasImsStock): void
    {
        $this->line((string) __('ims_reconcile.sec_managers'));
        if (! $hasImsStock) {
            $this->line('  '.__('ims_reconcile.compare_none'));

            return;
        }

        $adminIds = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('roles.code', 'ADMIN')->pluck('role_user.user_id');

        $table = [];
        foreach (ItemType::all() as $kind) {
            $count = DB::table('user_item_types')
                ->join('users', 'users.id', '=', 'user_item_types.user_id')
                ->where('users.lab_id', $labId)
                ->where('users.is_active', true)
                ->where('user_item_types.item_type', $kind)
                ->whereNotIn('users.id', $adminIds)
                ->count();

            $note = $count === 0
                ? __('ims_reconcile.warn_no_manager')
                : ($count > 1 ? __('ims_reconcile.warn_many_managers') : __('ims_reconcile.ok'));
            $table[] = [(string) __('ims_reconcile.kind_'.$kind), $count, $note];
        }

        $this->table([
            (string) __('ims_reconcile.col_kind'),
            (string) __('ims_reconcile.col_managers'),
            (string) __('ims_reconcile.col_flag'),
        ], $table);
    }
}
