<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $qty
 * @property numeric-string|null $unit_price
 * @property \Illuminate\Support\Carbon|null $expiry_date
 */
#[Fillable([
    'ims_receipt_id', 'line_no', 'item_id', 'item_code_raw', 'name_raw', 'lot_no', 'qty',
    'unit_id', 'unit_price', 'expiry_date', 'remark',
])]
class ImsReceiptLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'expiry_date' => 'date',
        ];
    }

    /** @return BelongsTo<ImsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ImsReceipt::class, 'ims_receipt_id');
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
}
