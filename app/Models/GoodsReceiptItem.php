<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $qty_per_container
 * @property numeric-string $qty_total_base
 * @property numeric-string|null $unit_price
 */
#[Fillable([
    'goods_receipt_id', 'line_no', 'item_id', 'container_count', 'qty_per_container',
    'unit_id', 'qty_total_base', 'lot_no', 'expiry_date', 'location_id', 'unit_price',
])]
class GoodsReceiptItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty_per_container' => 'decimal:6',
            'qty_total_base' => 'decimal:6',
            'unit_price' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
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

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
