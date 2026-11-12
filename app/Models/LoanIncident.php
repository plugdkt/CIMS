<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * ITM-2610-051 step 4 (BR-L11): one damage / loss case of a loan line.
 *
 * Written by LoanIncidentService only (forceFill): nothing but the free-text description is mass-assignable.
 * A result, once recorded, is final (spec 8.3): this model refuses to update a row that is no longer PENDING, and the
 * database trigger `trg_loan_incidents_final` refuses it too.
 *
 * `value_snapshot` is a reference value for the branch's staff; it is never shown to the borrower.
 *
 * @property int $id
 * @property string $ulid
 * @property int $loan_id
 * @property int $loan_line_id
 * @property string $kind
 * @property numeric-string $qty
 * @property string $description
 * @property string $resolution
 * @property string|null $disposition
 * @property numeric-string|null $value_snapshot
 * @property string|null $external_ref
 * @property int $recorded_by
 * @property int|null $resolved_by
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property \Illuminate\Support\Carbon $created_at
 */
#[Fillable(['description'])]
class LoanIncident extends Model
{
    public const KINDS = ['DAMAGED', 'LOST'];

    /** The results a PENDING incident can end in. */
    public const RESOLUTIONS = ['REPAIRED', 'REPLACED', 'COMPENSATED', 'WAIVED'];

    public const DISPOSITIONS = ['RETURN_TO_SERVICE', 'WRITE_OFF'];

    /** `attachments.doc_type` values an incident may carry: photos of the item, and the outside letter / memo. */
    public const FILE_TYPES = ['PHOTO', 'LOAN_DOC'];

    protected static function booted(): void
    {
        static::creating(function (self $incident) {
            $incident->ulid ??= (string) Str::ulid();
        });

        static::updating(function (self $incident) {
            if ($incident->getOriginal('resolution') !== 'PENDING') {
                throw new LogicException('a resolved loan incident is final');
            }
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
            'qty' => 'decimal:6',
            'value_snapshot' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->resolution === 'PENDING';
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<LoanLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(LoanLine::class, 'loan_line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return HasMany<LoanMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(LoanMovement::class, 'incident_id')->orderBy('id');
    }

    /**
     * Photos (PHOTO) and the outside letter / memo (LOAN_DOC). Who may open them is LoanIncidentPolicy::view —
     * never the borrower.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'owner', 'owner_type', 'owner_id')->orderBy('id');
    }
}
