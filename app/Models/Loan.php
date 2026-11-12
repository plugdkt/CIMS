<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * ITM-2610-051 step 2: one loan document (header).
 *
 * `status`, `borrower_status`, `advisor_id`, `lab_id`, `borrower_id`, `created_by` and every decision column are
 * deliberately NOT fillable (BR-L14): they are set by LoanService / LoanApprovalService with forceFill(), never
 * from a request. Only the free-form request details are mass-assignable.
 *
 * @property int $id
 * @property string $ulid
 * @property string $doc_no
 * @property int $lab_id
 * @property int $borrower_id
 * @property int $created_by
 * @property string $borrower_status
 * @property string $purpose_type
 * @property \Illuminate\Support\Carbon $loan_date
 * @property \Illuminate\Support\Carbon $due_date
 * @property \Illuminate\Support\Carbon $original_due_date
 * @property int|null $advisor_id
 * @property \Illuminate\Support\Carbon|null $advisor_signed_at
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $handed_out_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property int $renew_count
 * @property string $status
 */
#[Fillable(['purpose_type', 'purpose_detail', 'loan_date', 'due_date', 'remark'])]
class Loan extends Model
{
    /** Statuses in which the document can still be cancelled (before hand-out). */
    public const CANCELLABLE = ['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED'];

    /** Statuses a manager may still decide on. */
    public const AWAITING_MANAGER = ['SUBMITTED', 'ADVISOR_APPROVED'];

    /** Step 3: statuses in which things are with the borrower (returns and renewals happen here; "overdue" sits on top). */
    public const OUT = ['ON_LOAN', 'PARTIALLY_RETURNED'];

    protected static function booted(): void
    {
        static::creating(function (self $loan) {
            $loan->ulid ??= (string) Str::ulid();
        });
    }

    /** AGENT RULE #9: public URL identifiers are ULIDs, never auto-increment IDs. */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'loan_date' => 'date',
            'due_date' => 'date',
            'original_due_date' => 'date',
            'advisor_signed_at' => 'datetime',
            'approved_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'handed_out_at' => 'datetime',
            'closed_at' => 'datetime',
            'renew_count' => 'integer',
        ];
    }

    public function isOut(): bool
    {
        return in_array($this->status, self::OUT, true);
    }

    /**
     * BR-L08: "overdue" is computed, never stored: things are still out and the due date is before today.
     * (A scheduler that misses a run therefore cannot leave a wrong status behind.)
     *
     * Step 4: a loan whose pieces have ALL come back (or were written down as damaged / lost) but which stays
     * PARTIALLY_RETURNED because an incident is still waiting for its result (BR-L12) is NOT overdue: nothing is
     * with the borrower any more, so they must not be flagged or blocked (BR-L08) for the branch's pending decision.
     */
    public function isOverdue(): bool
    {
        return $this->isOut() && $this->due_date->lt(CarbonImmutable::today()) && $this->hasOutstanding();
    }

    /** True while at least one line still has pieces with the borrower (uses the loaded `lines` when present). */
    public function hasOutstanding(): bool
    {
        return $this->lines->contains(fn (LoanLine $line) => bccomp($line->outstanding(), '0', 6) > 0);
    }

    /** Whole calendar days past the due date (0 when not overdue). */
    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) $this->due_date->copy()->startOfDay()->diffInDays(CarbonImmutable::today()) : 0;
    }

    /**
     * The same rule as isOverdue(), for queries; `$graceDays` > 0 keeps only loans overdue by MORE than that many days.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query, int $graceDays = 0): void
    {
        $query->whereIn('status', self::OUT)
            ->whereDate('due_date', '<', CarbonImmutable::today()->subDays(max(0, $graceDays))->toDateString())
            // Step 4: something must still be with the borrower (see isOverdue()). Static SQL, no input.
            ->whereHas('lines', fn ($line) => $line->whereRaw(
                'COALESCE(loan_lines.qty_approved, loan_lines.qty) - loan_lines.qty_returned_ok - loan_lines.qty_returned_damaged - loan_lines.qty_lost > 0'
            ));
    }

    public function isStudentBorrower(): bool
    {
        return $this->borrower_status === 'STUDENT';
    }

    /** True when somebody other than the borrower typed the request in (FR-LN-14). */
    public function isOnBehalf(): bool
    {
        return $this->created_by !== $this->borrower_id;
    }

    /** The key of the status label in lang/th/loans.php. */
    public function statusLabelKey(): string
    {
        if ($this->status === 'SUBMITTED') {
            return $this->isStudentBorrower() ? 'loans.loan_status_waiting_advisor' : 'loans.loan_status_waiting_manager';
        }
        if ($this->status === 'ADVISOR_APPROVED') {
            return 'loans.loan_status_waiting_manager';
        }

        return 'loans.loan_status_'.strtolower($this->status);
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<User, $this> */
    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<LoanLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(LoanLine::class)->orderBy('line_no');
    }

    /** @return HasMany<LoanDueChange, $this> */
    public function dueChanges(): HasMany
    {
        return $this->hasMany(LoanDueChange::class)->orderBy('seq');
    }

    /** @return HasMany<LoanMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(LoanMovement::class)->orderBy('id');
    }

    /** @return HasMany<LoanIncident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(LoanIncident::class)->orderBy('id');
    }

    /** @return HasMany<LoanApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(LoanApproval::class)->orderBy('id');
    }
}
