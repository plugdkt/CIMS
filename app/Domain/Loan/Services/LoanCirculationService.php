<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 step 3: the counter — hand-out (APPROVED -> ON_LOAN) and returns (-> PARTIALLY_RETURNED / CLOSED).
 * Step 4: a return may also take pieces back DAMAGED or write them down as LOST; each such part becomes an incident
 * (LoanIncidentService::record), and the loan is not closed while an incident is PENDING (BR-L12).
 * The quantities of `loan_assets` and the `loan_movements` rows are written by LoanLedgerService only; this class
 * owns the document side (status, the lines' condition and returned quantities) and calls the ledger per line.
 *
 * Who: LoanAccess::isHandlerFor — an active holder of `loan.handout` (scientist / warehouse manager) of the LOAN's
 * own branch; no branch, another branch or an ADMIN-only account is refused. Checked here and again in the ledger.
 *
 * The borrower is NOT banned from handing out / taking back their own loan (CEO: "same rule as approvals", like
 * REQ_SELF_ISSUE on requisitions): the control is the record. Every hand-out / return writes an audit row, action
 * LOAN_HANDOUT / LOAN_RETURN, or LOAN_SELF_HANDOUT / LOAN_SELF_RETURN when the person at the counter is the borrower.
 * (Spec BR-L04 originally said "never the borrower"; this follows the later CEO rule and is flagged for confirmation.)
 *
 * Concurrency: one transaction per action, the loan row locked first and its status checked on the locked row, then
 * the asset rows locked one by one in id order (the same order LoanApprovalService uses). Two people pressing
 * "hand out" together: the second fails on the status. Two returns of the same pieces: the second sees the first's
 * `qty_returned_ok` on the re-read lines and is refused. Any failure rolls the whole action back.
 */
final class LoanCirculationService
{
    private const MIN_WEAR_REASON = 10;

    private const MAX_NOTE = 255;

    /** Step 4: an incident's description (`loan_incidents.description`). */
    private const MIN_DESCRIPTION = 10;

    private const MAX_DESCRIPTION = 500;

    public function __construct(
        private readonly LoanState $state,
        private readonly LoanAccess $access,
        private readonly LoanLedgerService $ledger,
        private readonly LoanIncidentService $incidents,
    ) {
    }

    /**
     * Hands out every line of an APPROVED loan at once (no partial hand-out, spec 8.1), recording the condition of
     * each line (BR-L09). Availability is checked again per asset under its row lock (BR-L02, third point).
     *
     * @param  array<int|string, array{condition?: string|null, note?: string|null}>  $conditions  line_no => condition (+ note)
     *
     * @throws LoanDomainException
     */
    public function handOut(User $actor, Loan $loan, array $conditions): Loan
    {
        return DB::transaction(function () use ($actor, $loan, $conditions) {
            $locked = $this->lock($loan);
            $this->assertHandler($actor, $locked);

            // Status first: a second click (or a cancelled loan) fails on the transition, before any stock check.
            $next = $this->state->apply($locked->status, 'handout', $locked->borrower_status);

            if ($locked->due_date->lt(CarbonImmutable::today())) {
                // Handing it out now would make it overdue on the spot: cancel it and make a new request.
                throw new LoanDomainException((string) __('loans.error.handout_due_passed'));
            }

            $lines = $locked->lines()->get();
            if ($lines->isEmpty()) {
                throw new LoanDomainException((string) __('loans.error.no_lines'));
            }

            /** @var array<int, array{0: string, 1: string|null}> $recorded line id => [condition, note] */
            $recorded = [];
            foreach ($lines as $line) {
                // An APPROVED loan has qty_approved on every line (LoanApprovalService keeps that invariant).
                if ($line->qty_approved === null) {
                    throw new LoanDomainException((string) __('loans.error.invalid_transition'));
                }
                $row = $conditions[$line->line_no] ?? [];
                $condition = (string) ($row['condition'] ?? '');
                if (! in_array($condition, LoanLedgerService::CONDITIONS, true)) {
                    throw new LoanDomainException((string) __('loans.error.condition_required'));
                }
                $note = $this->note($row['note'] ?? null);
                if ($condition === 'DAMAGED' && $note === null) {
                    throw new LoanDomainException((string) __('loans.error.condition_note_required'));
                }
                $recorded[$line->id] = [$condition, $note];
            }

            $assets = LoanAsset::query()->with('item')->whereIn('id', $lines->pluck('loan_asset_id'))->get()->keyBy('id');

            foreach ($lines->sortBy('loan_asset_id') as $line) {
                $asset = $assets->get($line->loan_asset_id);
                if ($asset === null) {
                    throw new LoanDomainException((string) __('loans.error.asset_not_lendable'));
                }
                [$condition, $note] = $recorded[$line->id];

                $this->ledger->handOut($actor, $asset, $locked, $line, $line->qtyOut(), $condition, $note);
                $line->forceFill(['condition_out' => $condition, 'condition_out_note' => $note])->save();
            }

            $locked->forceFill(['status' => $next, 'handed_out_at' => now()])->save();

            $this->audit($locked, $actor, 'HANDOUT', ['status' => $next, 'lines' => $lines->count()]);

            return $locked;
        });
    }

    /**
     * Takes pieces back, line by line (a partial return is fine: the loan becomes PARTIALLY_RETURNED). The loan is
     * CLOSED in the same transaction when nothing is left with the borrower AND no incident is PENDING (BR-L12);
     * with nothing out but an incident still open it stays PARTIALLY_RETURNED until LoanIncidentService records the
     * last result.
     *
     * One row per line can carry up to three parts (FR-LN-21):
     *   `qty` (+ `condition`, `note`)  pieces that came back usable -> RETURN_OK. A worse condition than at hand-out
     *                                   needs a wear reason of at least 10 characters (BR-L09).
     *   `qty_damaged`                   pieces that came back damaged -> an incident (DAMAGED) + RETURN_DAMAGED.
     *   `qty_lost`                      pieces that will not come back -> an incident (LOST) + MARK_LOST.
     *   `incident_note`                 what happened, at least 10 characters, required with either of the two.
     * The older single-outcome shape still works: `outcome` = DAMAGED / LOST makes `qty` that kind of incident and
     * `note` its description. Damaged / lost pieces are "unavailable" (not lendable) until the result is recorded.
     *
     * The incidents written by this call are on the returned loan as the relation `recordedIncidents` (for the
     * controller's notification; empty when there were none).
     *
     * @param  array<int|string, array{qty?: string|int|null, condition?: string|null, note?: string|null, outcome?: string|null, qty_damaged?: string|int|null, qty_lost?: string|int|null, incident_note?: string|null}>  $returns  line_no => what came back
     *
     * @throws LoanDomainException
     */
    public function receiveReturn(User $actor, Loan $loan, array $returns): Loan
    {
        return DB::transaction(function () use ($actor, $loan, $returns) {
            $locked = $this->lock($loan);
            $this->assertHandler($actor, $locked);

            if (! $locked->isOut()) {
                throw new LoanDomainException((string) __('loans.error.invalid_transition'));
            }

            // Read under the loan's lock: an earlier return's quantities are already in these rows.
            $lines = $locked->lines()->get()->keyBy('line_no');

            /** @var list<array{line: LoanLine, ok: numeric-string, damaged: numeric-string, lost: numeric-string, condition: string, note: string|null, description: string|null}> $plan */
            $plan = [];
            foreach ($returns as $lineNo => $row) {
                $ok = $this->wholeOrZero($row['qty'] ?? null);
                $damaged = $this->wholeOrZero($row['qty_damaged'] ?? null);
                $lost = $this->wholeOrZero($row['qty_lost'] ?? null);
                $note = $this->note($row['note'] ?? null);
                $description = $this->text($row['incident_note'] ?? null, self::MAX_DESCRIPTION);

                $outcome = strtoupper(trim((string) ($row['outcome'] ?? 'OK')));
                if (! in_array($outcome, ['OK', 'DAMAGED', 'LOST'], true)) {
                    throw new LoanDomainException((string) __('loans.error.return_type_invalid'));
                }
                if ($outcome !== 'OK') {
                    // The single-outcome shape: the whole `qty` is that kind of incident, `note` describes it.
                    if ($outcome === 'DAMAGED') {
                        $damaged = bcadd($damaged, $ok, 6);
                    } else {
                        $lost = bcadd($lost, $ok, 6);
                    }
                    $ok = '0.000000';
                    $description ??= $note;
                }

                $all = bcadd(bcadd($ok, $damaged, 6), $lost, 6);
                if (bccomp($all, '0', 6) === 0) {
                    continue;
                }

                $line = $lines->get((int) $lineNo);
                if ($line === null) {
                    throw new LoanDomainException((string) __('loans.error.line_mismatch'));
                }
                if (bccomp($all, $line->outstanding(), 6) > 0) {
                    throw new LoanDomainException((string) __('loans.error.return_too_much'));
                }

                $condition = (string) ($row['condition'] ?? '');
                if (bccomp($ok, '0', 6) > 0) {
                    if (! in_array($condition, LoanLedgerService::CONDITIONS, true)) {
                        throw new LoanDomainException((string) __('loans.error.condition_required'));
                    }
                    if ($this->worse($condition, $line->condition_out) && mb_strlen((string) $note) < self::MIN_WEAR_REASON) {
                        throw new LoanDomainException((string) __('loans.error.wear_reason_required'));
                    }
                }
                if ((bccomp($damaged, '0', 6) > 0 || bccomp($lost, '0', 6) > 0) && mb_strlen((string) $description) < self::MIN_DESCRIPTION) {
                    throw new LoanDomainException((string) __('loans.error.incident_description_required'));
                }

                $plan[] = ['line' => $line, 'ok' => $ok, 'damaged' => $damaged, 'lost' => $lost, 'condition' => $condition, 'note' => $note, 'description' => $description];
            }

            if ($plan === []) {
                throw new LoanDomainException((string) __('loans.error.return_nothing'));
            }

            usort($plan, fn (array $a, array $b) => $a['line']->loan_asset_id <=> $b['line']->loan_asset_id);
            $assets = LoanAsset::query()->whereIn('id', array_map(fn (array $p) => $p['line']->loan_asset_id, $plan))->get()->keyBy('id');

            $summary = [];
            $recorded = [];
            foreach ($plan as $part) {
                $line = $part['line'];
                $asset = $assets->get($line->loan_asset_id);
                if ($asset === null) {
                    throw new LoanDomainException((string) __('loans.error.line_mismatch'));
                }

                if (bccomp($part['ok'], '0', 6) > 0) {
                    $this->ledger->takeBack($actor, $asset, $locked, $line, 'RETURN_OK', $part['ok'], $part['condition'], $part['note']);
                    $line->forceFill(['qty_returned_ok' => bcadd($line->qty_returned_ok, $part['ok'], 6)])->save();
                    $summary[] = ['line_no' => $line->line_no, 'qty' => $part['ok'], 'outcome' => 'OK', 'condition' => $part['condition']];
                }

                foreach (['DAMAGED' => $part['damaged'], 'LOST' => $part['lost']] as $kind => $qty) {
                    if (bccomp($qty, '0', 6) <= 0) {
                        continue;
                    }
                    // Writes the incident row, its RETURN_DAMAGED / MARK_LOST movement and the line's quantity.
                    $incident = $this->incidents->record($actor, $locked, $line, $asset, $kind, $qty, (string) $part['description']);
                    $recorded[] = $incident;
                    $summary[] = ['line_no' => $line->line_no, 'qty' => $qty, 'outcome' => $kind, 'incident_id' => $incident->id];
                }
            }

            $stillOut = $lines->contains(fn (LoanLine $line) => bccomp($line->outstanding(), '0', 6) > 0);
            // BR-L12: CLOSED only when nothing is out AND no incident of this loan is PENDING (read under the loan's
            // lock; LoanIncidentService::resolve takes the same lock, so the two can never both miss the close).
            $pendingIncident = $this->incidents->hasPending($locked);

            $next = $this->state->apply($locked->status, $stillOut || $pendingIncident ? 'returnPartial' : 'close', $locked->borrower_status);

            $locked->forceFill(['status' => $next, 'closed_at' => $next === 'CLOSED' ? now() : null])->save();

            $this->audit($locked, $actor, 'RETURN', [
                'status' => $next,
                'closed' => $next === 'CLOSED',
                'pending_incident' => $pendingIncident,
                'returned' => $summary,
            ]);

            $locked->setRelation('recordedIncidents', new EloquentCollection($recorded));

            return $locked;
        });
    }

    private function lock(Loan $loan): Loan
    {
        return Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
    }

    private function assertHandler(User $actor, Loan $loan): void
    {
        if (! $this->access->isHandlerFor($actor, (int) $loan->lab_id)) {
            throw new LoanDomainException((string) __('loans.error.handler_not_eligible'));
        }
    }

    /** True when `$now` is a worse condition than the one written down at hand-out (unknown = GOOD). */
    private function worse(string $now, ?string $out): bool
    {
        $rank = array_flip(LoanLedgerService::CONDITIONS);

        return $rank[$now] > ($rank[$out ?? 'GOOD'] ?? 0);
    }

    private function note(?string $value): ?string
    {
        return $this->text($value, self::MAX_NOTE);
    }

    private function text(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * A quantity typed on the return page: blank / 0 = nothing, otherwise a whole number.
     *
     * @return numeric-string
     *
     * @throws LoanDomainException
     */
    private function wholeOrZero(string|int|null $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '0.000000';
        }
        if (preg_match('/^[0-9]{1,9}$/', $text) !== 1) {
            throw new LoanDomainException((string) __('loans.error.qty_whole'));
        }

        return bcadd((string) (int) $text, '0', 6);
    }

    /**
     * One audit row per hand-out / return. "SELF" = the person at the counter is the borrower (allowed, recorded);
     * the other overlaps (approver, on-behalf creator) are written as plain facts for the auditor.
     *
     * @param  'HANDOUT'|'RETURN'  $verb
     * @param  array<string, mixed>  $extra
     */
    private function audit(Loan $loan, User $actor, string $verb, array $extra): void
    {
        $self = $loan->borrower_id === $actor->id;

        AuditLog::record(
            action: 'LOAN_'.($self ? 'SELF_' : '').$verb,
            userId: $actor->id,
            username: $actor->username,
            entityType: Loan::class,
            entityId: $loan->id,
            newValue: array_merge([
                'doc_no' => $loan->doc_no,
                'lab_id' => $loan->lab_id,
                'borrower_id' => $loan->borrower_id,
                'handler_id' => $actor->id,
                'handler_is_borrower' => $self,
                'handler_is_approver' => $loan->approved_by !== null && $loan->approved_by === $actor->id,
                'handler_is_creator' => $loan->isOnBehalf() && $loan->created_by === $actor->id,
            ], $extra),
            message: $self ? 'borrower = handler' : null,
        );
    }
}
