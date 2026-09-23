<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $qty_requested
 * @property numeric-string $qty_requested_base
 * @property numeric-string|null $qty_approved
 * @property numeric-string|null $qty_approved_base
 * @property numeric-string $qty_issued_base
 * @property numeric-string $qty_returned_base
 * @property int|null $overage_approved_by
 */
#[Fillable([
    'requisition_id', 'line_no', 'item_id', 'qty_requested', 'unit_id',
    'qty_requested_base', 'qty_approved', 'qty_approved_base',
    'qty_issued_base', 'qty_returned_base', 'reference_doc', 'remark',
    'overage_approved_by',
])]
class RequisitionItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty_requested' => 'decimal:6',
            'qty_requested_base' => 'decimal:6',
            'qty_approved' => 'decimal:6',
            'qty_approved_base' => 'decimal:6',
            'qty_issued_base' => 'decimal:6',
            'qty_returned_base' => 'decimal:6',
        ];
    }

    /**
     * User-requested 2026-09-23: the ceiling every issuance check compares against — the
     * requested amount, unless a warehouse manager approved a different (lower) one. Falls
     * back to `qty_requested_base` for any line decided before this feature shipped
     * (`qty_approved_base` is nullable, not backfilled).
     *
     * @return numeric-string
     */
    public function approvedCeilingBase(): string
    {
        return $this->qty_approved_base ?? $this->qty_requested_base;
    }

    /** @return BelongsTo<Requisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function overageApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overage_approved_by');
    }
}
