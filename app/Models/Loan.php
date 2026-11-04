<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $status
 */
#[Fillable(['purpose_type', 'purpose_detail', 'loan_date', 'due_date', 'remark'])]
class Loan extends Model
{
    /** Statuses in which the document can still be cancelled (before hand-out). */
    public const CANCELLABLE = ['DRAFT', 'SUBMITTED', 'ADVISOR_APPROVED', 'APPROVED'];

    /** Statuses a manager may still decide on. */
    public const AWAITING_MANAGER = ['SUBMITTED', 'ADVISOR_APPROVED'];

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
        ];
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

    /** @return HasMany<LoanApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(LoanApproval::class)->orderBy('id');
    }
}
