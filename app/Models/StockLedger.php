<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $txn_date
 * @property numeric-string $qty_in_base
 * @property numeric-string $qty_out_base
 * @property numeric-string $balance_base
 */
#[Fillable([
    'item_id', 'container_id', 'txn_date', 'txn_type', 'ref_type', 'ref_id', 'ref_doc_no',
    'qty_in_base', 'qty_out_base', 'balance_base', 'display_unit_id', 'issuer_id',
    'receiver_id', 'receiver_name', 'signature_hash', 'remark', 'created_by', 'created_at',
    'prev_row_hash', 'row_hash',
])]
class StockLedger extends Model
{
    protected $table = 'stock_ledger';

    public $timestamps = false;

    /**
     * BR-08's hash includes `created_at` down to the microsecond
     * (`format('Y-m-d\TH:i:s.u')`), and the column is `DATETIME(6)` — without this,
     * Eloquent's default second-precision format would truncate microseconds on
     * save, and the row_hash recomputed on a later read would never match what
     * was stored at write time.
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'qty_in_base' => 'decimal:6',
            'qty_out_base' => 'decimal:6',
            'balance_base' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function displayUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'display_unit_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Container, $this> */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
