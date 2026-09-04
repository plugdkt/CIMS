<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $system_qty_base
 * @property numeric-string|null $counted_qty_base
 * @property numeric-string|null $diff_base
 * @property \Illuminate\Support\Carbon|null $counted_at
 * @property int|null $counted_by
 */
#[Fillable([
    'stock_take_id', 'container_id', 'system_qty_base', 'counted_qty_base',
    'diff_base', 'reason', 'counted_by', 'counted_at',
])]
class StockTakeLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'system_qty_base' => 'decimal:6',
            'counted_qty_base' => 'decimal:6',
            'diff_base' => 'decimal:6',
            'counted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockTake, $this> */
    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    /** @return BelongsTo<Container, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /** @return BelongsTo<User, $this> */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
