<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §5.2 / T-047: one row per (item, month) — a running-balance cache so a future balance
 * lookup can start from a month's `closing_base` instead of rescanning `stock_ledger`
 * from account inception. Never addressed by URL (no UI/report reads it yet — see
 * CLAUDE.md), so no ULID/getRouteKeyName is needed here (AGENT RULE #9 only applies to
 * route-bound models). Not append-only — a re-run for the same period safely overwrites
 * its own row (`uq_snapshot` on item_id+period_ym) via `updateOrCreate`.
 *
 * @property numeric-string $opening_base
 * @property numeric-string $total_in_base
 * @property numeric-string $total_out_base
 * @property numeric-string $closing_base
 * @property \Illuminate\Support\Carbon $generated_at
 */
#[Fillable([
    'item_id', 'period_ym', 'opening_base', 'total_in_base', 'total_out_base',
    'closing_base', 'last_ledger_id', 'generated_at',
])]
class LedgerSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'opening_base' => 'decimal:6',
            'total_in_base' => 'decimal:6',
            'total_out_base' => 'decimal:6',
            'closing_base' => 'decimal:6',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
