<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $qty_base
 * @property numeric-string $balance_after_base
 */
#[Fillable([
    'ims_lot_id', 'type', 'qty_base', 'balance_after_base', 'display_unit_id', 'ref_doc_no',
    'remark', 'created_by',
])]
class ImsMovement extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty_base' => 'decimal:6',
            'balance_after_base' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ImsLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ImsLot::class, 'ims_lot_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
