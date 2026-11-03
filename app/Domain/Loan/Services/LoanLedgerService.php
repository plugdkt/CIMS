<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\LoanAsset;
use App\Models\LoanMovement;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ITM-2610-051: the ONLY writer of `loan_assets` quantities and `loan_movements` (same rule as
 * "stock_ledger is written from LedgerService only"). It never touches stock_ledger.
 *
 * Step 1 covers REGISTER, ADJUST_IN / ADJUST_OUT and RETIRE; the other movement types (HANDOUT, returns,
 * incidents) arrive with their steps and will be added here. Every change runs in one DB::transaction with
 * a row lock on the asset (BR-L02), so two clicks can never both pass the same check.
 *
 * Separation of duties is enforced here, not only in the UI (spec §4.2): whoever adjusts or retires needs
 * a DIFFERENT approver who holds `loan.asset_manage` for that branch (ADMIN counts for every branch). The
 * rule applies to ADMIN too. ADMIN may write loan_movements (CEO exception for this module); stock_ledger
 * stays closed to ADMIN and is not touched here.
 */
final class LoanLedgerService
{
    private const MIN_NOTE = 10;

    private const MIN_REF = 5;

    public function __construct(private readonly LoanLedgerHasher $hasher)
    {
    }

    /**
     * REGISTER a new asset. `qty` is a whole number (UNIT is always 1).
     *
     * @param  array{lab_id: int, item_id: int, tracking_mode: string, qty: string|int, asset_tag?: string|null, location_id?: int|null, max_loan_days?: int|null, replacement_value?: string|float|int|null, condition_note?: string|null}  $data
     */
    public function register(User $actor, array $data): LoanAsset
    {
        $labId = (int) $data['lab_id'];
        $this->assertManagesLab($actor, $labId);

        $item = Item::with('baseUnit')->find((int) $data['item_id']);
        if ($item === null || ! $item->is_active) {
            throw new LoanDomainException((string) __('loans.error.item_not_found'));
        }
        // BR-L01: only items counted in whole pieces (base unit of dimension COUNT) can be lent.
        $baseUnit = $item->baseUnit;
        if ($baseUnit === null || $baseUnit->dimension !== 'COUNT') {
            throw new LoanDomainException((string) __('loans.error.item_not_countable'));
        }

        $mode = (string) $data['tracking_mode'];
        if (! in_array($mode, ['UNIT', 'POOL'], true)) {
            throw new LoanDomainException((string) __('loans.error.mode_invalid'));
        }

        $qty = $this->wholeQty($data['qty']);
        if ($mode === 'UNIT' && $qty !== '1.000000') {
            throw new LoanDomainException((string) __('loans.error.unit_qty_one'));
        }

        $locationId = isset($data['location_id']) ? (int) $data['location_id'] : null;
        if ($locationId !== null) {
            $location = Location::find($locationId);
            if ($location === null || (int) $location->lab_id !== $labId) {
                throw new LoanDomainException((string) __('loans.error.location_not_in_lab'));
            }
        }

        try {
            return $this->registerLocked($actor, $labId, $item, $mode, $qty, $locationId, $data);
        } catch (QueryException $e) {
            // Two simultaneous registrations: the unique indexes (pool_key / asset_tag) win, the user gets a sentence, not a 500.
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate entry')) {
                // The key name decides: the whole exception text also quotes the INSERT, which names asset_tag.
                throw new LoanDomainException((string) __(str_contains($e->getMessage(), "for key 'uq_la_pool'") ? 'loans.error.pool_exists' : 'loans.error.tag_exists'));
            }

            throw $e;
        }
    }

    /**
     * @param  numeric-string  $qty
     * @param  array<string, mixed>  $data
     */
    private function registerLocked(User $actor, int $labId, Item $item, string $mode, string $qty, ?int $locationId, array $data): LoanAsset
    {
        return DB::transaction(function () use ($actor, $labId, $item, $mode, $qty, $locationId, $data) {
            // Only an ACTIVE pool of the same pair blocks (a retired one frees the pair, see pool_key).
            if ($mode === 'POOL' && LoanAsset::where('lab_id', $labId)->where('item_id', $item->id)->where('tracking_mode', 'POOL')->where('status', 'ACTIVE')->exists()) {
                throw new LoanDomainException((string) __('loans.error.pool_exists'));
            }

            $tag = null;
            if ($mode === 'UNIT') {
                $tag = isset($data['asset_tag']) && trim((string) $data['asset_tag']) !== ''
                    ? trim((string) $data['asset_tag'])
                    : $this->newAssetTag();
                if (LoanAsset::where('asset_tag', $tag)->exists()) {
                    throw new LoanDomainException((string) __('loans.error.tag_exists'));
                }
            }

            $asset = new LoanAsset([
                'lab_id' => $labId,
                'item_id' => $item->id,
                'tracking_mode' => $mode,
                'asset_tag' => $tag,
                'location_id' => $locationId,
                'max_loan_days' => $data['max_loan_days'] ?? null,
                'replacement_value' => $data['replacement_value'] ?? null,
                'condition_note' => $data['condition_note'] ?? null,
            ]);
            // Quantities and status are not mass-assignable on purpose; they are set here, with the movement.
            $asset->forceFill([
                'qty_total' => $qty,
                'qty_on_loan' => '0.000000',
                'qty_unavailable' => '0.000000',
                'status' => 'ACTIVE',
            ]);
            $asset->save();

            $this->append($asset, 'REGISTER', $qty, $actor, null, null, null);

            AuditLog::record(
                action: 'LOAN_ASSET_REGISTER',
                userId: $actor->id,
                username: $actor->username,
                entityType: LoanAsset::class,
                entityId: $asset->id,
                newValue: ['lab_id' => $labId, 'item_id' => $item->id, 'tracking_mode' => $mode, 'qty' => $qty],
            );

            return $asset;
        });
    }

    /**
     * ADJUST_IN / ADJUST_OUT on a POOL asset (fixing a count): needs a note, and a different approver.
     * The total can never drop to zero this way (retire the asset instead) nor below what is out or unusable.
     *
     * @param  string  $direction  'IN' or 'OUT' (validated by LoanAssetAdjustRequest)
     */
    public function adjust(User $actor, LoanAsset $asset, string $direction, string|int $qty, string $note, ?string $externalRef, int $approvedBy): LoanAsset
    {
        $qty = $this->wholeQty($qty);

        return DB::transaction(function () use ($actor, $asset, $direction, $qty, $note, $externalRef, $approvedBy) {
            $locked = $this->lock($asset);
            $this->assertActive($locked);
            $this->assertManagesLab($actor, (int) $locked->lab_id);
            if ($locked->tracking_mode !== 'POOL') {
                throw new LoanDomainException((string) __('loans.error.adjust_pool_only'));
            }
            $this->assertNote($note);
            $approver = $this->assertApprover($actor, $locked, $approvedBy);

            $total = $locked->qty_total;
            if ($direction === 'IN') {
                $newTotal = bcadd($total, $qty, 6);
                $type = 'ADJUST_IN';
            } else {
                $type = 'ADJUST_OUT';
                // Keep at least one piece (to remove the whole asset, retire it) and never go below out + unusable.
                $newTotal = bcsub($total, $qty, 6);
                $floor = bcadd($locked->qty_on_loan, $locked->qty_unavailable, 6);
                if (bccomp($newTotal, '1', 6) < 0 || bccomp($newTotal, $floor, 6) < 0) {
                    throw new LoanDomainException((string) __('loans.error.adjust_out_too_much'));
                }
                // An asset that has ever been lent leaves no quiet way out: an outside reference is required.
                if ($this->everHandedOut($locked) && mb_strlen(trim((string) $externalRef)) < self::MIN_REF) {
                    throw new LoanDomainException((string) __('loans.error.external_ref_required'));
                }
            }

            $this->append($locked, $type, $qty, $actor, $approver, $note, $externalRef, totalAfter: $newTotal);

            AuditLog::record(
                action: 'LOAN_ASSET_'.$type,
                userId: $actor->id,
                username: $actor->username,
                entityType: LoanAsset::class,
                entityId: $locked->id,
                oldValue: ['qty_total' => $total],
                newValue: ['qty_total' => $newTotal, 'approved_by' => $approver->id],
            );

            return $locked->refresh();
        });
    }

    /** RETIRE an asset (only while nothing is out). The movement records the quantity retired; the totals stay as they were. */
    public function retire(User $actor, LoanAsset $asset, string $note, int $approvedBy): LoanAsset
    {
        return DB::transaction(function () use ($actor, $asset, $note, $approvedBy) {
            $locked = $this->lock($asset);
            $this->assertActive($locked);
            $this->assertManagesLab($actor, (int) $locked->lab_id);
            $this->assertNote($note);
            $approver = $this->assertApprover($actor, $locked, $approvedBy);

            if (bccomp($locked->qty_on_loan, '0', 6) > 0) {
                throw new LoanDomainException((string) __('loans.error.retire_while_on_loan'));
            }

            $this->append($locked, 'RETIRE', $locked->qty_total, $actor, $approver, $note, null);
            $locked->forceFill(['status' => 'RETIRED'])->save();

            AuditLog::record(
                action: 'LOAN_ASSET_RETIRE',
                userId: $actor->id,
                username: $actor->username,
                entityType: LoanAsset::class,
                entityId: $locked->id,
                oldValue: ['status' => 'ACTIVE'],
                newValue: ['status' => 'RETIRED', 'approved_by' => $approver->id],
            );

            return $locked->refresh();
        });
    }

    /**
     * Appends one movement and moves the asset's quantities to the "after" values in the same step.
     * Must be called inside the caller's transaction with the asset row already locked.
     */
    private function append(
        LoanAsset $asset,
        string $type,
        string $qty,
        User $actor,
        ?User $approver,
        ?string $note,
        ?string $externalRef,
        ?string $totalAfter = null,
    ): LoanMovement {
        $totalAfter ??= $asset->qty_total;

        $prev = LoanMovement::where('loan_asset_id', $asset->id)->orderByDesc('id')->value('row_hash');

        $row = new LoanMovement([
            'loan_asset_id' => $asset->id,
            'lab_id' => $asset->lab_id,
            'movement_type' => $type,
            'qty' => $qty,
            'qty_total_after' => $totalAfter,
            'qty_on_loan_after' => $asset->qty_on_loan,
            'qty_unavailable_after' => $asset->qty_unavailable,
            'note' => $note,
            'external_ref' => $externalRef !== null && trim($externalRef) !== '' ? trim($externalRef) : null,
            'created_by' => $actor->id,
            'approved_by' => $approver?->id,
            'created_at' => now(),
            'prev_row_hash' => $prev,
        ]);
        $row->row_hash = $this->hasher->compute($row);
        $row->save();

        if ($asset->qty_total !== $totalAfter) {
            $asset->forceFill(['qty_total' => $totalAfter])->save();
        }

        return $row;
    }

    private function lock(LoanAsset $asset): LoanAsset
    {
        return LoanAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
    }

    private function assertActive(LoanAsset $asset): void
    {
        if ($asset->status !== 'ACTIVE') {
            throw new LoanDomainException((string) __('loans.error.asset_retired'));
        }
    }

    private function assertNote(string $note): void
    {
        if (mb_strlen(trim($note)) < self::MIN_NOTE) {
            throw new LoanDomainException((string) __('loans.error.note_too_short'));
        }
    }

    /** The actor must hold loan.asset_manage and belong to the asset's branch (ADMIN: any branch, decided by role not lab_id). */
    private function assertManagesLab(User $actor, int $labId): void
    {
        if (! $actor->is_active || ! $actor->hasPermission('loan.asset_manage')) {
            throw new LoanDomainException((string) __('loans.error.not_allowed'));
        }
        if (! $actor->hasRole('ADMIN') && ($actor->lab_id === null || (int) $actor->lab_id !== $labId)) {
            throw new LoanDomainException((string) __('loans.error.not_allowed'));
        }
    }

    /** BR-L17: a different, active person who may manage this asset's branch. */
    private function assertApprover(User $actor, LoanAsset $asset, int $approvedBy): User
    {
        $approver = User::find($approvedBy);
        if (
            $approver === null
            || ! $approver->is_active
            || $approver->id === $actor->id
            || ! $approver->hasPermission('loan.asset_manage')
            || (! $approver->hasRole('ADMIN') && ($approver->lab_id === null || (int) $approver->lab_id !== (int) $asset->lab_id))
        ) {
            throw new LoanDomainException((string) __('loans.error.approver_invalid'));
        }

        return $approver;
    }

    private function everHandedOut(LoanAsset $asset): bool
    {
        return LoanMovement::where('loan_asset_id', $asset->id)->where('movement_type', 'HANDOUT')->exists();
    }

    /**
     * Whole positive numbers only, as a DECIMAL(18,6) string ("3" -> "3.000000").
     *
     * @return numeric-string
     */
    private function wholeQty(string|int $qty): string
    {
        $text = trim((string) $qty);
        if (preg_match('/^[0-9]{1,9}$/', $text) !== 1 || (int) $text < 1) {
            throw new LoanDomainException((string) __('loans.error.qty_whole'));
        }

        // bcadd returns a numeric-string with exactly six decimals ("3" -> "3.000000"), no cast needed.
        return bcadd((string) (int) $text, '0', 6);
    }

    private function newAssetTag(): string
    {
        do {
            $tag = 'LA-'.Str::upper(Str::random(8));
        } while (LoanAsset::where('asset_tag', $tag)->exists());

        return $tag;
    }
}
