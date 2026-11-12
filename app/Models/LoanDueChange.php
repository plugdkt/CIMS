<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ITM-2610-051 step 3 (BR-L07): one request to move a loan's due date. The first one of a loan is APPROVED at once;
 * later ones wait (PENDING) for an approver of the branch. `loans.due_date` only changes when a row becomes APPROVED,
 * and no row is ever rewritten after it was decided, so the old dates stay readable.
 *
 * Nothing that decides anything is mass-assignable: `loan_id`, `seq`, `status`, `requested_by`, `approved_by` and the
 * dates are set by LoanRenewalService with forceFill(). Addressed in URLs by the loan's ULID plus `seq`, never by id.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $seq
 * @property \Illuminate\Support\Carbon $old_due_date
 * @property \Illuminate\Support\Carbon $new_due_date
 * @property int $requested_by
 * @property string|null $reason
 * @property string $status
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $acted_at
 * @property string|null $decision_note
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['reason'])]
class LoanDueChange extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_due_date' => 'date',
            'new_due_date' => 'date',
            'acted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
