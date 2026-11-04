<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ITM-2610-051 step 2: one ADVISOR or MANAGER decision on a loan (same shape as requisition_approvals).
 *
 * @property \Illuminate\Support\Carbon $acted_at
 */
#[Fillable(['loan_id', 'step', 'actor_id', 'decision', 'reason', 'acted_at', 'ip_address'])]
class LoanApproval extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
