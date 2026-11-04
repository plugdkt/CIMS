<?php

declare(strict_types=1);

namespace App\Domain\Loan\Services;

use App\Domain\Loan\Exceptions\LoanDomainException;
use App\Domain\Shared\DocumentNumberGenerator;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\LoanLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ITM-2610-051 step 2: create / edit / submit / cancel a loan document. The decisions (advisor, manager) are in
 * LoanApprovalService. Nothing here touches `loan_assets` quantities or `loan_movements`: a request does NOT reserve
 * anything (BR-L02 re-checks availability at approval and at hand-out), so cancelling after approval has nothing to undo.
 *
 * Rules kept here rather than only in the UI (spec 4.2): the branch of the loan is the BORROWER's branch and every
 * line must be an asset of that branch (BR-L05); the borrower status and advisor are copied from the borrower's
 * profile, never from the request (BR-L14), and are fixed at submit.
 */
final class LoanService
{
    private const MIN_CANCEL_REASON = 5;

    public function __construct(
        private readonly LoanState $state,
        private readonly LoanAccess $access,
        private readonly BorrowerResolver $borrowers,
        private readonly DocumentNumberGenerator $numbers,
    ) {
    }

    /**
     * Creates a DRAFT. `$lines` maps a loan asset's ULID to the quantity wanted (blank / 0 rows are ignored).
     *
     * @param  array{purpose_type: string, purpose_detail?: string|null, loan_date: string, due_date: string, remark?: string|null}  $data
     * @param  array<string, string|int|null>  $lines
     *
     * @throws LoanDomainException
     */
    public function createDraft(User $actor, BorrowerContext $borrower, array $data, array $lines): Loan
    {
        if ($borrower->labId === null) {
            throw new LoanDomainException((string) __('loans.error.borrower_no_lab'));
        }
        $this->assertAdvisorOnFile($borrower->user, $borrower->isStudent(), $borrower->advisorId);

        $wanted = $this->parseLines($lines);
        $assets = $this->loadAssets($wanted, $borrower->labId);
        $this->assertPeriod($data['loan_date'], $data['due_date'], $assets);

        return DB::transaction(function () use ($actor, $borrower, $data, $wanted, $assets) {
            $loan = new Loan($this->headerFields($data));
            $loan->forceFill([
                'doc_no' => $this->numbers->next('LON'),
                'lab_id' => $borrower->labId,
                'borrower_id' => $borrower->user->id,
                'created_by' => $actor->id,
                'borrower_status' => $borrower->status,
                'advisor_id' => $borrower->isStudent() ? $borrower->advisorId : null,
                'original_due_date' => $data['due_date'],
                'status' => 'DRAFT',
            ])->save();

            $this->writeLines($loan, $wanted, $assets);

            AuditLog::record(
                action: 'LOAN_CREATE',
                userId: $actor->id,
                username: $actor->username,
                entityType: Loan::class,
                entityId: $loan->id,
                newValue: [
                    'doc_no' => $loan->doc_no,
                    'lab_id' => $loan->lab_id,
                    'borrower_id' => $loan->borrower_id,
                    'created_by' => $loan->created_by,
                    'on_behalf' => $loan->isOnBehalf(),
                ],
            );

            return $loan;
        });
    }

    /**
     * Edits a DRAFT (header and lines). Borrower, branch, status and advisor never change here.
     *
     * @param  array{purpose_type: string, purpose_detail?: string|null, loan_date: string, due_date: string, remark?: string|null}  $data
     * @param  array<string, string|int|null>  $lines
     *
     * @throws LoanDomainException
     */
    public function updateDraft(User $actor, Loan $loan, array $data, array $lines): Loan
    {
        $wanted = $this->parseLines($lines);
        $assets = $this->loadAssets($wanted, (int) $loan->lab_id);
        $this->assertPeriod($data['loan_date'], $data['due_date'], $assets);

        return DB::transaction(function () use ($actor, $loan, $data, $wanted, $assets) {
            $locked = $this->lock($loan);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'DRAFT') {
                throw new LoanDomainException((string) __('loans.error.not_draft'));
            }

            $locked->fill($this->headerFields($data));
            $locked->forceFill(['original_due_date' => $data['due_date']])->save();

            LoanLine::where('loan_id', $locked->id)->delete();
            $this->writeLines($locked, $wanted, $assets);

            return $locked;
        });
    }

    /**
     * DRAFT -> SUBMITTED. The profile values are read again and become final here (BR-L14); availability is checked
     * now (BR-L02, first of the three points: request / approval / hand-out).
     *
     * @throws LoanDomainException
     */
    public function submit(User $actor, Loan $loan): Loan
    {
        return DB::transaction(function () use ($actor, $loan) {
            $locked = $this->lock($loan);
            $this->assertOwner($actor, $locked);
            if ($locked->status !== 'DRAFT') {
                throw new LoanDomainException((string) __('loans.error.not_draft'));
            }

            $borrowerUser = User::with('roles.permissions')->find($locked->borrower_id);
            if ($borrowerUser === null || ! $borrowerUser->is_active) {
                throw new LoanDomainException((string) __('loans.error.borrower_invalid'));
            }
            $context = $this->borrowers->forSelf($borrowerUser);
            if ($context->labId === null) {
                throw new LoanDomainException((string) __('loans.error.borrower_no_lab'));
            }
            if ($context->labId !== (int) $locked->lab_id) {
                // The borrower was moved to another branch after the draft was made: the lines belong to the old one.
                throw new LoanDomainException((string) __('loans.error.lab_changed'));
            }
            $this->assertAdvisorOnFile($borrowerUser, $context->isStudent(), $context->advisorId);
            if ($context->isStudent()) {
                $advisor = User::with('roles.permissions')->find($context->advisorId);
                if ($advisor === null || ! $advisor->is_active || ! $advisor->hasPermission('loan.approve_advisor')) {
                    throw new LoanDomainException((string) __('loans.error.advisor_not_eligible'));
                }
            }

            $lines = $locked->lines()->get();
            if ($lines->isEmpty()) {
                throw new LoanDomainException((string) __('loans.error.no_lines'));
            }
            if ($locked->loan_date->lt(CarbonImmutable::today())) {
                throw new LoanDomainException((string) __('loans.error.loan_date_past'));
            }
            $assets = LoanAsset::with('item')->whereIn('id', $lines->pluck('loan_asset_id'))->get()->keyBy('id');
            foreach ($lines as $line) {
                $asset = $assets->get($line->loan_asset_id);
                $this->assertLendable($asset, (int) $locked->lab_id);
                /** @var LoanAsset $asset */
                if (bccomp($line->qty, $asset->available(), 6) > 0) {
                    throw new LoanDomainException((string) __('loans.error.not_enough_available', ['item' => (string) $asset->item?->name_th]));
                }
            }

            // The snapshot taken now is the one the whole workflow uses from here on.
            $locked->forceFill([
                'borrower_status' => $context->status,
                'advisor_id' => $context->isStudent() ? $context->advisorId : null,
                'status' => $this->state->apply($locked->status, 'submit', $context->status),
                'submitted_at' => now(),
            ])->save();

            AuditLog::record(
                action: 'LOAN_SUBMIT',
                userId: $actor->id,
                username: $actor->username,
                entityType: Loan::class,
                entityId: $locked->id,
                newValue: [
                    'doc_no' => $locked->doc_no,
                    'lab_id' => $locked->lab_id,
                    'borrower_id' => $locked->borrower_id,
                    'created_by' => $locked->created_by,
                    'on_behalf' => $locked->isOnBehalf(),
                    'borrower_status' => $locked->borrower_status,
                    'advisor_id' => $locked->advisor_id,
                ],
            );

            return $locked;
        });
    }

    /**
     * Cancels a loan before hand-out. Allowed for the borrower / the person who typed it in (reason optional), and
     * for an approver of the loan's branch (reason required, not on somebody's DRAFT). ADMIN cancels only their own.
     *
     * @throws LoanDomainException
     */
    public function cancel(User $actor, Loan $loan, ?string $reason): Loan
    {
        return DB::transaction(function () use ($actor, $loan, $reason) {
            $locked = $this->lock($loan);

            $owner = $this->access->isOwner($actor, $locked);
            $branchApprover = $locked->status !== 'DRAFT' && $this->access->isApproverFor($actor, (int) $locked->lab_id);
            if (! $owner && ! $branchApprover) {
                throw new LoanDomainException((string) __('loans.error.not_allowed'));
            }

            $reason = $reason === null ? null : trim($reason);
            if (! $owner && mb_strlen((string) $reason) < self::MIN_CANCEL_REASON) {
                throw new LoanDomainException((string) __('loans.error.cancel_reason_required'));
            }

            $previous = $locked->status;
            $next = $this->state->apply($previous, 'cancel', $locked->borrower_status);

            $locked->forceFill([
                'status' => $next,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason === '' ? null : $reason,
            ])->save();

            AuditLog::record(
                action: 'LOAN_CANCEL',
                userId: $actor->id,
                username: $actor->username,
                entityType: Loan::class,
                entityId: $locked->id,
                oldValue: ['status' => $previous],
                newValue: ['status' => $next, 'doc_no' => $locked->doc_no, 'by_borrower' => $actor->id === $locked->borrower_id],
            );

            return $locked;
        });
    }

    private function lock(Loan $loan): Loan
    {
        return Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
    }

    /** Only the borrower or the person who typed the request in may edit / submit it. */
    private function assertOwner(User $actor, Loan $loan): void
    {
        if (! $actor->is_active || ! $this->access->isOwner($actor, $loan)) {
            throw new LoanDomainException((string) __('loans.error.not_allowed'));
        }
    }

    /** BR-L14: a STUDENT needs an advisor on file, and never themselves. */
    private function assertAdvisorOnFile(User $borrower, bool $student, ?int $advisorId): void
    {
        if (! $student) {
            return;
        }
        if ($advisorId === null) {
            throw new LoanDomainException((string) __('loans.error.advisor_missing'));
        }
        if ($advisorId === $borrower->id) {
            throw new LoanDomainException((string) __('loans.error.advisor_is_borrower'));
        }
    }

    /**
     * @param  array<string, string|int|null>  $lines
     * @return array<string, numeric-string>  asset ulid => whole quantity (as a DECIMAL string)
     *
     * @throws LoanDomainException
     */
    private function parseLines(array $lines): array
    {
        $wanted = [];
        foreach ($lines as $ulid => $qty) {
            $text = trim((string) $qty);
            if ($text === '' || $text === '0') {
                continue;
            }
            if (preg_match('/^[0-9]{1,9}$/', $text) !== 1 || (int) $text < 1) {
                throw new LoanDomainException((string) __('loans.error.qty_whole'));
            }
            $wanted[(string) $ulid] = bcadd((string) (int) $text, '0', 6);
        }

        if ($wanted === []) {
            throw new LoanDomainException((string) __('loans.error.no_lines'));
        }
        if (count($wanted) > (int) config('loans.max_lines', 20)) {
            throw new LoanDomainException((string) __('loans.error.too_many_lines', ['max' => (int) config('loans.max_lines', 20)]));
        }

        return $wanted;
    }

    /**
     * @param  array<string, numeric-string>  $wanted
     * @return array<string, LoanAsset>  keyed by ulid
     *
     * @throws LoanDomainException
     */
    private function loadAssets(array $wanted, int $labId): array
    {
        $found = LoanAsset::with('item')->whereIn('ulid', array_keys($wanted))->get()->keyBy('ulid');

        $assets = [];
        foreach ($wanted as $ulid => $qty) {
            $asset = $found->get($ulid);
            $this->assertLendable($asset, $labId);
            /** @var LoanAsset $asset */
            if ($asset->tracking_mode === 'UNIT' && $qty !== '1.000000') {
                throw new LoanDomainException((string) __('loans.error.unit_qty_one'));
            }
            $assets[$ulid] = $asset;
        }

        return $assets;
    }

    /** BR-L05 / BR-L15: an asset of the loan's own branch, still ACTIVE. */
    private function assertLendable(?LoanAsset $asset, int $labId): void
    {
        if ($asset === null || $asset->status !== 'ACTIVE') {
            throw new LoanDomainException((string) __('loans.error.asset_not_lendable'));
        }
        if ((int) $asset->lab_id !== $labId) {
            throw new LoanDomainException((string) __('loans.error.asset_other_lab'));
        }
    }

    /**
     * BR-L06: the period is counted in calendar days from the pick-up date; the standard limit is in config and an
     * asset's own `max_loan_days` can only shorten it.
     *
     * @param  array<string, LoanAsset>  $assets
     *
     * @throws LoanDomainException
     */
    private function assertPeriod(string $loanDate, string $dueDate, array $assets): void
    {
        $from = CarbonImmutable::parse($loanDate)->startOfDay();
        $to = CarbonImmutable::parse($dueDate)->startOfDay();
        $today = CarbonImmutable::today();

        if ($from->lt($today)) {
            throw new LoanDomainException((string) __('loans.error.loan_date_past'));
        }
        if ($from->gt($today->addDays((int) config('loans.max_lead_days', 30)))) {
            throw new LoanDomainException((string) __('loans.error.loan_date_too_far', ['days' => (int) config('loans.max_lead_days', 30)]));
        }
        if ($to->lt($from)) {
            throw new LoanDomainException((string) __('loans.error.due_before_loan'));
        }

        $limit = (int) config('loans.max_loan_days', 30);
        foreach ($assets as $asset) {
            if ($asset->max_loan_days !== null) {
                $limit = min($limit, (int) $asset->max_loan_days);
            }
        }
        if ((int) $from->diffInDays($to) > $limit) {
            throw new LoanDomainException((string) __('loans.error.period_too_long', ['days' => $limit]));
        }
    }

    /**
     * @param  array{purpose_type: string, purpose_detail?: string|null, loan_date: string, due_date: string, remark?: string|null}  $data
     * @return array<string, mixed>
     */
    private function headerFields(array $data): array
    {
        return [
            'purpose_type' => $data['purpose_type'],
            'purpose_detail' => $this->blankToNull($data['purpose_detail'] ?? null),
            'loan_date' => $data['loan_date'],
            'due_date' => $data['due_date'],
            'remark' => $this->blankToNull($data['remark'] ?? null),
        ];
    }

    /**
     * @param  array<string, numeric-string>  $wanted
     * @param  array<string, LoanAsset>  $assets
     */
    private function writeLines(Loan $loan, array $wanted, array $assets): void
    {
        $lineNo = 1;
        foreach ($wanted as $ulid => $qty) {
            $line = new LoanLine(['line_no' => $lineNo++, 'qty' => $qty]);
            $line->forceFill(['loan_id' => $loan->id, 'loan_asset_id' => $assets[$ulid]->id])->save();
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
