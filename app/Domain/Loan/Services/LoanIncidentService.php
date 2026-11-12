<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanIncident;
use App\Models\LoanLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 step 4 (BR-L11, BR-L12): damage / loss cases of a loan line.
 *
 *  - record(): called by LoanCirculationService while it takes pieces back (inside ITS transaction, loan row locked).
 *    Writes the incident (PENDING), its RETURN_DAMAGED / MARK_LOST movement and the line's quantity. Who: the person
 *    at the counter (LoanAccess::isHandlerFor — a scientist / warehouse manager of the loan's branch, never an
 *    ADMIN-only account).
 *  - resolve(): records the result the management decided OUTSIDE the system (the system has no compensation
 *    workflow and takes no money, BR-L10). Who: an active holder of `loan.asset_approve` of the loan's own branch who
 *    is NOT the person who recorded the incident, NOT the borrower and NOT the person who typed the loan in for the
 *    borrower (LoanLedgerService::resolverProblem). An ADMIN-only account cannot.
 *    (Spec BR-L11 names the permission `loan.incident_resolve`, which SCIENTIST does not hold and ADMIN does; the CEO's
 *    rule for ADJUST / RETIRE — "another scientist may approve; ADMIN cannot stand in" — is applied here instead.)
 *
 * Results allowed (BR-L11 point 3):
 *   DAMAGED -> REPAIRED (back in service) | COMPENSATED / WAIVED (back in service OR written off) | REPLACED
 *   LOST    -> COMPENSATED | WAIVED | REPLACED, always written off (never REPAIRED, never "back in service")
 * Evidence (points 4-5): REPAIRED needs a note of 10+ characters and the condition after repair (GOOD / FAIR). Every
 * other result needs BOTH an outside reference (5+) and a note (10+); "back in service" also needs the condition;
 * a write-off also needs at least one attached LOAN_DOC file on the incident.
 * Movements (point 6), all in ONE transaction: back in service -> REPAIR_DONE; written off -> WRITE_OFF;
 * REPLACED -> WRITE_OFF then REPLACE_IN (received by a named counter person, approved by the resolver).
 *
 * A recorded result is final. When the last PENDING incident of a loan is resolved and nothing is left with the
 * borrower, the loan is CLOSED in the same transaction (BR-L12).
 *
 * Locks: the loan row first, then the incident row, then the asset row (inside the ledger) — the same loan -> asset
 * order LoanCirculationService uses, so a return and a resolution on one loan cannot deadlock or both miss the close.
 */
final class LoanIncidentService
{
    private const MIN_NOTE = 10;

    private const MIN_REF = 5;

    public function __construct(
        private readonly LoanLedgerService $ledger,
        private readonly LoanAccess $access,
        private readonly LoanState $state,
    ) {
    }

    /** BR-L12: does this loan still have an incident waiting for its result? */
    public function hasPending(Loan $loan): bool
    {
        return LoanIncident::query()->where('loan_id', $loan->id)->where('resolution', 'PENDING')->exists();
    }

    /**
     * Call inside the caller's transaction with the loan row locked (LoanCirculationService::receiveReturn).
     *
     * @param  string  $kind  DAMAGED or LOST
     * @param  numeric-string  $qty  whole pieces, not more than the line still has out
     *
     * @throws LoanDomainException
     */
    public function record(User $actor, Loan $loan, LoanLine $line, LoanAsset $asset, string $kind, string $qty, string $description): LoanIncident
    {
        return DB::transaction(function () use ($actor, $loan, $line, $asset, $kind, $qty, $description) {
            if (! $this->access->isHandlerFor($actor, (int) $loan->lab_id)) {
                throw new LoanDomainException((string) __('loans.error.handler_not_eligible'));
            }
            if (! in_array($kind, LoanIncident::KINDS, true)) {
                throw new LoanDomainException((string) __('loans.error.return_type_invalid'));
            }
            if (! $loan->isOut() || $line->loan_id !== $loan->id) {
                throw new LoanDomainException((string) __('loans.error.line_mismatch'));
            }
            $description = trim($description);
            if (mb_strlen($description) < self::MIN_NOTE) {
                throw new LoanDomainException((string) __('loans.error.incident_description_required'));
            }

            $incident = new LoanIncident(['description' => mb_substr($description, 0, 500)]);
            $incident->forceFill([
                'loan_id' => $loan->id,
                'loan_line_id' => $line->id,
                'kind' => $kind,
                'qty' => $qty,
                'resolution' => 'PENDING',
                'recorded_by' => $actor->id,
            ])->save();
            $incident->setRelation('line', $line);

            // The ledger checks the quantity against what the line and the asset still have out, under the asset's lock.
            $this->ledger->takeBackIncident($actor, $asset, $loan, $line, $incident, $incident->description);

            if ($kind === 'DAMAGED') {
                $line->forceFill(['qty_returned_damaged' => bcadd($line->qty_returned_damaged, $qty, 6)])->save();
            } else {
                $line->forceFill(['qty_lost' => bcadd($line->qty_lost, $qty, 6)])->save();
            }

            AuditLog::record(
                action: 'LOAN_INCIDENT_RECORD',
                userId: $actor->id,
                username: $actor->username,
                entityType: LoanIncident::class,
                entityId: $incident->id,
                newValue: [
                    'doc_no' => $loan->doc_no,
                    'loan_id' => $loan->id,
                    'lab_id' => $loan->lab_id,
                    'line_no' => $line->line_no,
                    'loan_asset_id' => $asset->id,
                    'kind' => $kind,
                    'qty' => $incident->qty,
                    'recorded_by' => $actor->id,
                    'recorder_is_borrower' => $loan->borrower_id === $actor->id,
                ],
            );

            return $incident;
        });
    }

    /**
     * Records the result of a PENDING incident (see the class comment for the rules).
     *
     * @param  array{resolution: string, disposition?: string|null, condition_after?: string|null, external_ref?: string|null, resolution_note?: string|null, receiver_id?: int|string|null}  $data
     *
     * @throws LoanDomainException
     */
    public function resolve(User $resolver, LoanIncident $incident, array $data): LoanIncident
    {
        return DB::transaction(function () use ($resolver, $incident, $data) {
            $loan = Loan::query()->whereKey($incident->loan_id)->lockForUpdate()->firstOrFail();
            $locked = LoanIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

            // Decided already (two people at once: the first wins).
            if (! $locked->isPending()) {
                throw new LoanDomainException((string) __('loans.error.incident_not_pending'));
            }

            $problem = $this->ledger->resolverProblem($resolver, $loan, $locked);
            if ($problem !== null) {
                throw new LoanDomainException((string) __($problem));
            }

            $line = LoanLine::query()->whereKey($locked->loan_line_id)->first();
            $asset = $line === null ? null : LoanAsset::query()->whereKey($line->loan_asset_id)->first();
            if ($line === null || $asset === null || $line->loan_id !== $loan->id) {
                throw new LoanDomainException((string) __('loans.error.line_mismatch'));
            }
            $locked->setRelation('line', $line);

            $resolution = strtoupper(trim($data['resolution']));
            $disposition = $this->disposition($locked->kind, $resolution, $this->blankToNull($data['disposition'] ?? null));
            $ref = $this->blankToNull($data['external_ref'] ?? null);
            $note = $this->blankToNull($data['resolution_note'] ?? null);
            $condition = $this->blankToNull($data['condition_after'] ?? null);

            // Evidence. A note always; an outside reference for everything except a plain repair (when given there,
            // it must still be a real one).
            if ($note === null || mb_strlen($note) < self::MIN_NOTE) {
                throw new LoanDomainException((string) __('loans.error.incident_note_required'));
            }
            if (($resolution !== 'REPAIRED' || $ref !== null) && ($ref === null || mb_strlen($ref) < self::MIN_REF)) {
                throw new LoanDomainException((string) __('loans.error.incident_ref_required'));
            }
            if ($disposition === 'RETURN_TO_SERVICE' && ! in_array($condition, ['GOOD', 'FAIR'], true)) {
                throw new LoanDomainException((string) __('loans.error.incident_condition_after'));
            }
            if ($disposition === 'WRITE_OFF' && ! $locked->attachments()->where('doc_type', 'LOAN_DOC')->exists()) {
                throw new LoanDomainException((string) __('loans.error.incident_doc_required'));
            }

            $receiver = null;
            if ($resolution === 'REPLACED') {
                $receiver = User::query()->with('roles.permissions')->find((int) ($data['receiver_id'] ?? 0));
                if ($receiver === null) {
                    throw new LoanDomainException((string) __('loans.error.replace_receiver_invalid'));
                }
            }

            // The reference value on this day (whole pieces x the asset's replacement value); never shown to the borrower.
            $value = $asset->replacement_value === null ? null : bcmul($asset->replacement_value, $locked->qty, 2);

            if ($disposition === 'RETURN_TO_SERVICE') {
                $this->ledger->repairDone($resolver, $asset, $loan, $locked, (string) $condition, $note);
            } else {
                $this->ledger->writeOff($resolver, $asset, $loan, $locked, (string) $ref, $note, replacementFollows: $receiver !== null);
                if ($receiver !== null) {
                    $this->ledger->replaceIn($receiver, $resolver, $asset, $loan, $locked, (string) $ref, $note);
                }
            }

            $locked->forceFill([
                'resolution' => $resolution,
                'disposition' => $disposition,
                'value_snapshot' => $value,
                'external_ref' => $ref,
                'resolved_by' => $resolver->id,
                'resolved_at' => now(),
                'resolution_note' => mb_substr($note, 0, 500),
            ])->save();

            // BR-L12: the last open incident is done and nothing is left with the borrower -> the loan closes here.
            $closed = false;
            if ($loan->isOut() && ! $this->hasPending($loan)) {
                $stillOut = $loan->lines()->get()->contains(fn (LoanLine $l) => bccomp($l->outstanding(), '0', 6) > 0);
                if (! $stillOut) {
                    $next = $this->state->apply($loan->status, 'close', $loan->borrower_status);
                    $loan->forceFill(['status' => $next, 'closed_at' => now()])->save();
                    $closed = true;
                }
            }

            AuditLog::record(
                action: 'LOAN_INCIDENT_RESOLVE',
                userId: $resolver->id,
                username: $resolver->username,
                entityType: LoanIncident::class,
                entityId: $locked->id,
                oldValue: ['resolution' => 'PENDING'],
                newValue: [
                    'doc_no' => $loan->doc_no,
                    'loan_id' => $loan->id,
                    'lab_id' => $loan->lab_id,
                    'kind' => $locked->kind,
                    'qty' => $locked->qty,
                    'resolution' => $resolution,
                    'disposition' => $disposition,
                    'condition_after' => $disposition === 'RETURN_TO_SERVICE' ? $condition : null,
                    'external_ref' => $ref,
                    'recorded_by' => $locked->recorded_by,
                    'resolved_by' => $resolver->id,
                    'replacement_received_by' => $receiver?->id,
                    'resolver_is_admin' => $resolver->hasRole('ADMIN'),
                    'loan_closed' => $closed,
                ],
            );

            $locked->setRelation('loan', $loan);

            return $locked;
        });
    }

    /**
     * BR-L11 point 3: which result goes with which kind, and what happens to the pieces. A forced disposition may be
     * left blank on the form; a value that contradicts it is refused (never silently changed).
     *
     * @return 'RETURN_TO_SERVICE'|'WRITE_OFF'
     *
     * @throws LoanDomainException
     */
    private function disposition(string $kind, string $resolution, ?string $asked): string
    {
        if (! in_array($resolution, LoanIncident::RESOLUTIONS, true)) {
            throw new LoanDomainException((string) __('loans.error.incident_resolution_invalid'));
        }
        if ($asked !== null && ! in_array($asked, LoanIncident::DISPOSITIONS, true)) {
            throw new LoanDomainException((string) __('loans.error.incident_resolution_invalid'));
        }

        if ($kind === 'LOST') {
            // Something that is gone cannot be repaired or put back in service.
            if ($resolution === 'REPAIRED' || $asked === 'RETURN_TO_SERVICE') {
                throw new LoanDomainException((string) __('loans.error.incident_lost_no_return'));
            }

            return 'WRITE_OFF';
        }

        $forced = match ($resolution) {
            'REPAIRED' => 'RETURN_TO_SERVICE',
            'REPLACED' => 'WRITE_OFF',
            default => null,
        };
        if ($forced !== null) {
            if ($asked !== null && $asked !== $forced) {
                throw new LoanDomainException((string) __('loans.error.incident_resolution_invalid'));
            }

            return $forced;
        }

        // COMPENSATED / WAIVED of a damaged piece: the form must say which.
        if ($asked === null) {
            throw new LoanDomainException((string) __('loans.error.incident_disposition_required'));
        }

        return $asked === 'WRITE_OFF' ? 'WRITE_OFF' : 'RETURN_TO_SERVICE';
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
