<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string|null $qty
 * @property numeric-string|null $pack_qty
 * @property numeric-string|null $unit_price
 * @property \Illuminate\Support\Carbon|null $expiry_date
 */
#[Fillable([
    'ims_receipt_id', 'line_no', 'item_id', 'item_code_raw', 'name_raw', 'lot_no', 'pack_qty', 'qty',
    'unit_id', 'unit_price', 'expiry_date', 'remark',
])]
class ImsReceiptLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:6',
            'pack_qty' => 'decimal:6',
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

    /**
     * The measurable quantity this line stands for: the explicit `qty` + `unit` if the manager
     * entered one, otherwise `pack_qty × the catalog's package size` (in the package's own
     * unit). Null when neither is available — the line is incomplete and cannot be confirmed.
     *
     * @return array{qty: numeric-string, unit: Unit}|null
     */
    public function resolvedQuantity(): ?array
    {
        $unit = $this->unit;
        if ($this->qty !== null && $unit !== null && bccomp($this->qty, '0', 6) > 0) {
            return ['qty' => $this->qty, 'unit' => $unit];
        }

        $item = $this->item;
        $packUnit = $item?->packageUnit;
        /** @var numeric-string|null $packageSize */
        $packageSize = $item?->package_size;
        if ($this->pack_qty !== null && bccomp($this->pack_qty, '0', 6) > 0
            && $packageSize !== null && bccomp($packageSize, '0', 6) > 0
            && $packUnit !== null
        ) {
            return ['qty' => bcmul($this->pack_qty, $packageSize, 6), 'unit' => $packUnit];
        }

        return null;
    }
}
