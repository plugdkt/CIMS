<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property \Illuminate\Support\Carbon $doc_date
 * @property \Illuminate\Support\Carbon|null $advisor_signed_at
 * @property \Illuminate\Support\Carbon|null $scientist_signed_at
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property list<string> $request_type
 * @property int|null $scientist_id
 * @property 'APPROVE'|'REJECT'|null $scientist_decision
 */
#[Fillable([
    'ulid', 'doc_no', 'lab_id', 'doc_date', 'requester_id', 'requester_status',
    'requester_phone', 'student_code', 'program', 'faculty', 'request_type',
    'purpose_type', 'purpose_detail', 'advisor_id', 'advisor_signed_at',
    'advisor_signature_hash', 'scientist_id', 'scientist_decision', 'reject_reason',
    'scientist_signed_at', 'status', 'submitted_at', 'completed_at',
])]
class Requisition extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $requisition) {
            $requisition->ulid ??= (string) Str::ulid();
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
            'doc_date' => 'date',
            'advisor_signed_at' => 'datetime',
            'scientist_signed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * `request_type` is a MySQL SET column (spec §5.2) — the driver reads/writes it as a
     * comma-separated string, so Eloquent's built-in `array` cast (which expects JSON)
     * doesn't apply here.
     *
     * @return Attribute<list<string>, list<string>>
     */
    protected function requestType(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null || $value === '' ? [] : explode(',', $value),
            set: fn (array $value) => implode(',', $value),
        );
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<User, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function scientist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scientist_id');
    }

    /** @return HasMany<RequisitionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class)->orderBy('line_no');
    }

    /** @return HasMany<RequisitionApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(RequisitionApproval::class);
    }
}
