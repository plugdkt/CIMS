<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanIncident;
use App\Models\LoanLine;
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
 * Step 1 covers REGISTER, ADJUST_IN / ADJUST_OUT and RETIRE; step 3 adds HANDOUT and RETURN_OK (called by
 * LoanCirculationService inside its own transaction on the locked loan). Step 4 adds the incident movements:
 * RETURN_DAMAGED / MARK_LOST (takeBackIncident, called by LoanCirculationService) and REPAIR_DONE / WRITE_OFF /
 * REPLACE_IN (called by LoanIncidentService when a result is recorded). Every change runs in one DB::transaction with
 * a row lock on the asset (BR-L02), so two clicks can never both pass the same check, and every row is written by
 * append() (which reads the previous row with a lock, so the hash chain cannot fork).
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

    /** The condition values of `loan_lines.condition_out` / `loan_movements.condition_recorded`, best first. */
    public const CONDITIONS = ['GOOD', 'FAIR', 'DAMAGED'];

    public function __construct(
        private readonly LoanLedgerHasher $hasher,
        private readonly LoanAccess $access,
    ) {
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

    /** RETIRE an asset (only while nothing is out, nothing is unavailable and no incident of it is PENDING). The movement records the quantity retired; the totals stay as they were. */
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
            // QA fix (ITM-2610-051): nor while a damage / loss case is still open. Its pieces sit in "unavailable"
            // until a result is recorded, and REPAIR_DONE / WRITE_OFF / REPLACE_IN need an ACTIVE asset to land on.
            if (
                bccomp($locked->qty_unavailable, '0', 6) > 0
                || LoanIncident::where('resolution', 'PENDING')->whereHas('line', fn ($q) => $q->where('loan_asset_id', $locked->id))->exists()
            ) {
                throw new LoanDomainException((string) __('loans.error.retire_while_incident'));
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
     * HANDOUT (step 3): `qty` pieces of this asset leave with the borrower of `$loan`. BR-L02's third check: the
     * quantity must still be available NOW, under the asset's row lock (approval reserved nothing), which is also
     * what stops a UNIT piece that is already out from going out twice (BR-L15) and two counters from handing out
     * the same last piece.
     *
     * Call inside the caller's transaction, with the loan row already locked.
     *
     * @param  numeric-string  $qty
     *
     * @throws LoanDomainException
     */
    public function handOut(User $actor, LoanAsset $asset, Loan $loan, LoanLine $line, string $qty, string $condition, ?string $note): LoanMovement
    {
        return DB::transaction(function () use ($actor, $asset, $loan, $line, $qty, $condition, $note) {
            $locked = $this->lock($asset);
            $this->assertCounter($actor, $locked, $loan, $line);
            $this->assertCondition($condition);
            $qty = $this->wholeDecimal($qty);

            if ($locked->status !== 'ACTIVE') {
                throw new LoanDomainException((string) __('loans.error.asset_not_lendable'));
            }
            if (bccomp($qty, $locked->available(), 6) > 0) {
                throw new LoanDomainException((string) __('loans.error.handout_not_available', ['item' => (string) $locked->item?->name_th]));
            }

            $locked->forceFill(['qty_on_loan' => bcadd($locked->qty_on_loan, $qty, 6)])->save();

            return $this->append($locked, 'HANDOUT', $qty, $actor, null, $this->blankToNull($note), null, loan: $loan, line: $line, condition: $condition);
        });
    }

    /**
     * An ordinary return of `qty` pieces of this line (RETURN_OK only — the pieces come back usable and are available
     * again). RETURN_DAMAGED and MARK_LOST move pieces to "unavailable" and always belong to an incident record: they
     * are refused here by name and written by takeBackIncident() only. A RETIRED asset still takes its pieces back.
     *
     * Call inside the caller's transaction, with the loan row already locked.
     *
     * @param  numeric-string  $qty
     *
     * @throws LoanDomainException
     */
    public function takeBack(User $actor, LoanAsset $asset, Loan $loan, LoanLine $line, string $type, string $qty, string $condition, ?string $note): LoanMovement
    {
        if (in_array($type, ['RETURN_DAMAGED', 'MARK_LOST'], true)) {
            throw new LoanDomainException((string) __('loans.error.incident_not_available'));
        }
        if ($type !== 'RETURN_OK') {
            throw new LoanDomainException((string) __('loans.error.return_type_invalid'));
        }

        return DB::transaction(function () use ($actor, $asset, $loan, $line, $qty, $condition, $note) {
            $locked = $this->lock($asset);
            $this->assertCounter($actor, $locked, $loan, $line);
            $this->assertCondition($condition);
            $qty = $this->wholeDecimal($qty);

            // Never more than is out: neither on this line (the caller's check, repeated here) nor on the asset.
            if (bccomp($qty, $line->outstanding(), 6) > 0 || bccomp($qty, $locked->qty_on_loan, 6) > 0) {
                throw new LoanDomainException((string) __('loans.error.return_too_much'));
            }

            $locked->forceFill(['qty_on_loan' => bcsub($locked->qty_on_loan, $qty, 6)])->save();

            return $this->append($locked, 'RETURN_OK', $qty, $actor, null, $this->blankToNull($note), null, loan: $loan, line: $line, condition: $condition);
        });
    }

    /**
     * Step 4 (BR-L11, point 1): pieces come back damaged (RETURN_DAMAGED) or are written down as lost (MARK_LOST).
     * Both move `qty` from "on loan" to "unavailable", so they are not counted as available while the incident is
     * PENDING. The type and the quantity come from the incident row itself, never from the caller.
     *
     * Call inside the caller's transaction, with the loan row already locked and BEFORE the line's returned
     * quantities are raised (the line must still show these pieces as outstanding).
     *
     * @throws LoanDomainException
     */
    public function takeBackIncident(User $actor, LoanAsset $asset, Loan $loan, LoanLine $line, LoanIncident $incident, ?string $note): LoanMovement
    {
        return DB::transaction(function () use ($actor, $asset, $loan, $line, $incident, $note) {
            $locked = $this->lock($asset);
            $this->assertCounter($actor, $locked, $loan, $line);
            $this->assertIncident($incident, $loan, $locked);
            if ($incident->loan_line_id !== $line->id || $incident->recorded_by !== $actor->id) {
                throw new LoanDomainException((string) __('loans.error.line_mismatch'));
            }
            $qty = $this->wholeDecimal($incident->qty);

            if (bccomp($qty, $line->outstanding(), 6) > 0 || bccomp($qty, $locked->qty_on_loan, 6) > 0) {
                throw new LoanDomainException((string) __('loans.error.return_too_much'));
            }

            $locked->forceFill([
                'qty_on_loan' => bcsub($locked->qty_on_loan, $qty, 6),
                'qty_unavailable' => bcadd($locked->qty_unavailable, $qty, 6),
            ])->save();

            $damaged = $incident->kind === 'DAMAGED';

            return $this->append(
                $locked,
                $damaged ? 'RETURN_DAMAGED' : 'MARK_LOST',
                $qty,
                $actor,
                null,
                $this->blankToNull($note),
                null,
                loan: $loan,
                line: $line,
                condition: $damaged ? 'DAMAGED' : null,
                incident: $incident,
            );
        });
    }

    /**
     * Step 4 (BR-L11, point 6): REPAIR_DONE — the pieces of a DAMAGED incident are usable again (repaired, or checked
     * and found usable): "unavailable" goes down, so they are available again. `$condition` is GOOD or FAIR.
     *
     * Call inside LoanIncidentService's transaction (loan and incident rows locked, incident still PENDING).
     *
     * @throws LoanDomainException
     */
    public function repairDone(User $resolver, LoanAsset $asset, Loan $loan, LoanIncident $incident, string $condition, string $note): LoanMovement
    {
        return DB::transaction(function () use ($resolver, $asset, $loan, $incident, $condition, $note) {
            $locked = $this->lock($asset);
            $this->assertIncident($incident, $loan, $locked);
            $this->assertResolver($resolver, $loan, $incident);
            if ($incident->kind !== 'DAMAGED') {
                throw new LoanDomainException((string) __('loans.error.incident_lost_no_return'));
            }
            if (! in_array($condition, ['GOOD', 'FAIR'], true)) {
                throw new LoanDomainException((string) __('loans.error.incident_condition_after'));
            }
            if (mb_strlen(trim($note)) < self::MIN_NOTE) {
                throw new LoanDomainException((string) __('loans.error.incident_note_required'));
            }
            $qty = $this->incidentQty($incident, $locked);

            $locked->forceFill(['qty_unavailable' => bcsub($locked->qty_unavailable, $qty, 6)])->save();

            return $this->append($locked, 'REPAIR_DONE', $qty, $resolver, null, trim($note), null, loan: $loan, line: $incident->line, condition: $condition, incident: $incident);
        });
    }

    /**
     * Step 4 (BR-L11, points 5-6): WRITE_OFF — the pieces of an incident leave the register for good: "unavailable"
     * and the total both go down. Needs an outside reference (5+ characters) AND a note (10+), always (BR-L17).
     *
     * An asset whose total reaches zero is marked RETIRED in the same step (there is nothing left to lend, and a
     * RETIRE movement of zero pieces cannot exist), unless a replacement follows at once (`$replacementFollows`,
     * the REPLACED result: the same register row carries on with the new piece).
     *
     * Call inside LoanIncidentService's transaction (loan and incident rows locked, incident still PENDING).
     *
     * @throws LoanDomainException
     */
    public function writeOff(User $resolver, LoanAsset $asset, Loan $loan, LoanIncident $incident, string $externalRef, string $note, bool $replacementFollows = false): LoanMovement
    {
        return DB::transaction(function () use ($resolver, $asset, $loan, $incident, $externalRef, $note, $replacementFollows) {
            $locked = $this->lock($asset);
            $this->assertIncident($incident, $loan, $locked);
            $this->assertResolver($resolver, $loan, $incident);
            $this->assertEvidence($externalRef, $note);
            $qty = $this->incidentQty($incident, $locked);

            $total = $locked->qty_total;
            $newTotal = bcsub($total, $qty, 6);
            $newUnavailable = bcsub($locked->qty_unavailable, $qty, 6);
            if (bccomp($newTotal, bcadd($locked->qty_on_loan, $newUnavailable, 6), 6) < 0) {
                throw new LoanDomainException((string) __('loans.error.incident_qty_mismatch'));
            }

            $locked->forceFill(['qty_unavailable' => $newUnavailable])->save();

            $row = $this->append($locked, 'WRITE_OFF', $qty, $resolver, null, trim($note), $externalRef, totalAfter: $newTotal, loan: $loan, line: $incident->line, incident: $incident);

            $retired = false;
            if (! $replacementFollows && $locked->status === 'ACTIVE' && bccomp($newTotal, '0', 6) === 0) {
                $locked->forceFill(['status' => 'RETIRED'])->save();
                $retired = true;
            }

            AuditLog::record(
                action: 'LOAN_WRITE_OFF',
                userId: $resolver->id,
                username: $resolver->username,
                entityType: LoanAsset::class,
                entityId: $locked->id,
                oldValue: ['qty_total' => $total],
                newValue: [
                    'qty_total' => $newTotal,
                    'qty' => $qty,
                    'loan_id' => $loan->id,
                    'doc_no' => $loan->doc_no,
                    'incident_id' => $incident->id,
                    'kind' => $incident->kind,
                    'external_ref' => trim($externalRef),
                    'recorded_by' => $incident->recorded_by,
                    'retired_empty' => $retired,
                ],
            );

            return $row;
        });
    }

    /**
     * Step 4 (BR-L11 point 6, BR-L17): REPLACE_IN — a replacement for written-off pieces comes into the register
     * (the total goes up; the pieces are available). Two different people, both written on the row:
     *   created_by  = `$receiver`, who physically took the replacement in: works the counter of the asset's branch
     *                 (LoanAccess::isHandlerFor), is not the borrower and not the approver;
     *   approved_by = `$approver`, the person recording the result (resolverProblem: `loan.asset_approve`, same
     *                 branch, not the incident's recorder, not the borrower, not the on-behalf creator; never an
     *                 ADMIN-only account). The DB CHECK `chk_lm_approval` refuses a row without a different approver.
     *
     * Call inside LoanIncidentService's transaction, right after writeOff(..., replacementFollows: true).
     *
     * @throws LoanDomainException
     */
    public function replaceIn(User $receiver, User $approver, LoanAsset $asset, Loan $loan, LoanIncident $incident, string $externalRef, string $note): LoanMovement
    {
        return DB::transaction(function () use ($receiver, $approver, $asset, $loan, $incident, $externalRef, $note) {
            $locked = $this->lock($asset);
            $this->assertActive($locked);
            $this->assertIncident($incident, $loan, $locked);
            $this->assertResolver($approver, $loan, $incident);
            $this->assertEvidence($externalRef, $note);
            if (
                $receiver->id === $approver->id
                || $receiver->id === $loan->borrower_id
                || ! $this->access->isHandlerFor($receiver, (int) $locked->lab_id)
            ) {
                throw new LoanDomainException((string) __('loans.error.replace_receiver_invalid'));
            }
            $qty = $this->wholeDecimal($incident->qty);

            $total = $locked->qty_total;
            $newTotal = bcadd($total, $qty, 6);

            $row = $this->append($locked, 'REPLACE_IN', $qty, $receiver, $approver, trim($note), $externalRef, totalAfter: $newTotal, loan: $loan, line: $incident->line, incident: $incident);

            AuditLog::record(
                action: 'LOAN_REPLACE_IN',
                userId: $approver->id,
                username: $approver->username,
                entityType: LoanAsset::class,
                entityId: $locked->id,
                oldValue: ['qty_total' => $total],
                newValue: [
                    'qty_total' => $newTotal,
                    'qty' => $qty,
                    'loan_id' => $loan->id,
                    'doc_no' => $loan->doc_no,
                    'incident_id' => $incident->id,
                    'received_by' => $receiver->id,
                    'approved_by' => $approver->id,
                    'external_ref' => trim($externalRef),
                ],
            );

            return $row;
        });
    }

    /**
     * Step 4: why this person may NOT record the result of this incident (null = they may). Public so
     * LoanIncidentPolicy and LoanIncidentService use the SAME rule as the ledger: active, holds `loan.asset_approve`
     * (scientists and warehouse managers; ADMIN alone holds none), belongs to the loan's own branch (a branch is
     * required of everyone), and is none of: the person who recorded the incident, the borrower, the person who typed
     * the loan in for the borrower (BR-L11 point 2). Returns the key of the message in lang/th/loans.php.
     */
    public function resolverProblem(User $user, Loan $loan, LoanIncident $incident): ?string
    {
        return $this->access->incidentResolverProblem($user, $loan, $incident);
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
        ?Loan $loan = null,
        ?LoanLine $line = null,
        ?string $condition = null,
        ?LoanIncident $incident = null,
    ): LoanMovement {
        $totalAfter ??= $asset->qty_total;

        // A LOCKING read, like LedgerService::lockLastRow(): a plain SELECT would answer from this transaction's
        // snapshot, which step 3 opens (reading the loan's lines) BEFORE the asset's row lock is won. Under two
        // simultaneous hand-outs / returns on one asset the second writer then chained to a row that was no longer
        // the last one, and loans:verify reported a broken chain (found by LoanConcurrencyTest CT-L01 / CT-L04).
        $prev = LoanMovement::where('loan_asset_id', $asset->id)->orderByDesc('id')->lockForUpdate()->value('row_hash');

        $row = new LoanMovement([
            'loan_asset_id' => $asset->id,
            'lab_id' => $asset->lab_id,
            'loan_id' => $loan?->id,
            'loan_line_id' => $line?->id,
            'incident_id' => $incident?->id,
            'movement_type' => $type,
            'qty' => $qty,
            'condition_recorded' => $condition,
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

    /**
     * Step 3: who may write a HANDOUT / RETURN row, and for what. The actor works the counter of the asset's branch
     * (LoanAccess::isHandlerFor: active, `loan.handout` from a role other than ADMIN, same branch, branch required),
     * and the loan, the line and the asset really belong together (BR-L05: a loan only lends its own branch's assets).
     * Nobody is banned for being the borrower (CEO: same rule as approvals); LoanCirculationService records that.
     */
    private function assertCounter(User $actor, LoanAsset $asset, Loan $loan, LoanLine $line): void
    {
        if (! $this->access->isHandlerFor($actor, (int) $asset->lab_id)) {
            throw new LoanDomainException((string) __('loans.error.handler_not_eligible'));
        }
        if ((int) $loan->lab_id !== (int) $asset->lab_id || $line->loan_id !== $loan->id || $line->loan_asset_id !== $asset->id) {
            throw new LoanDomainException((string) __('loans.error.line_mismatch'));
        }
    }

    /** Step 4: the incident is still open and really belongs to this loan and this asset. */
    private function assertIncident(LoanIncident $incident, Loan $loan, LoanAsset $asset): void
    {
        if (! $incident->isPending()) {
            throw new LoanDomainException((string) __('loans.error.incident_not_pending'));
        }
        $line = $incident->line;
        if (
            $incident->loan_id !== $loan->id
            || $line === null
            || $line->loan_id !== $loan->id
            || $line->loan_asset_id !== $asset->id
            || (int) $loan->lab_id !== (int) $asset->lab_id
        ) {
            throw new LoanDomainException((string) __('loans.error.line_mismatch'));
        }
    }

    private function assertResolver(User $resolver, Loan $loan, LoanIncident $incident): void
    {
        $problem = $this->resolverProblem($resolver, $loan, $incident);
        if ($problem !== null) {
            throw new LoanDomainException((string) __($problem));
        }
    }

    /** BR-L11 point 5 / BR-L17: an outside reference AND a note, both — never one without the other. */
    private function assertEvidence(string $externalRef, string $note): void
    {
        if (mb_strlen(trim($externalRef)) < self::MIN_REF) {
            throw new LoanDomainException((string) __('loans.error.incident_ref_required'));
        }
        if (mb_strlen(trim($note)) < self::MIN_NOTE) {
            throw new LoanDomainException((string) __('loans.error.incident_note_required'));
        }
    }

    /**
     * The incident's quantity, which must still sit in the asset's "unavailable" (it was put there by its own
     * RETURN_DAMAGED / MARK_LOST).
     *
     * @return numeric-string
     */
    private function incidentQty(LoanIncident $incident, LoanAsset $asset): string
    {
        $qty = $this->wholeDecimal($incident->qty);
        if (bccomp($qty, $asset->qty_unavailable, 6) > 0) {
            throw new LoanDomainException((string) __('loans.error.incident_qty_mismatch'));
        }

        return $qty;
    }

    private function assertCondition(string $condition): void
    {
        if (! in_array($condition, self::CONDITIONS, true)) {
            throw new LoanDomainException((string) __('loans.error.condition_required'));
        }
    }

    /**
     * A quantity that already is a DECIMAL string ("3.000000"): it must be a whole number above zero.
     *
     * @param  numeric-string  $qty
     * @return numeric-string
     */
    private function wholeDecimal(string $qty): string
    {
        if (preg_match('/^[0-9]{1,9}(\.0{1,6})?$/', $qty) !== 1 || bccomp($qty, '0', 6) <= 0) {
            throw new LoanDomainException((string) __('loans.error.qty_whole'));
        }

        return bcadd($qty, '0', 6);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * BR-L17 (changed by approvals.md K, CEO 2026-11-04): a different, active person of THIS asset's branch who holds
     * `loan.asset_approve` — the scientists of the branch and its warehouse managers. ADMIN cannot stand in (it holds
     * no `loan.asset_approve`, and a branch is required of everyone). Actor != approver is kept.
     */
    private function assertApprover(User $actor, LoanAsset $asset, int $approvedBy): User
    {
        $approver = User::find($approvedBy);
        if (
            $approver === null
            || ! $approver->is_active
            || $approver->id === $actor->id
            || ! $approver->hasPermission('loan.asset_approve')
            || $approver->lab_id === null
            || (int) $approver->lab_id !== (int) $asset->lab_id
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
