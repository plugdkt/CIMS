<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One purchase/receiving document printed from the university's own warehouse system.
 * DRAFT while a warehouse manager is still checking the lines; CONFIRMED once they
 * accept it, at which point each line becomes an {@see ImsLot}.
 *
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property array<string, mixed>|null $import_summary
 */
#[Fillable([
    'ulid', 'lab_id', 'doc_no', 'fiscal_year', 'purchase_round', 'source_file_path',
    'source_file_name', 'import_summary', 'source_file_hash', 'status', 'created_by', 'confirmed_by', 'confirmed_at',
])]
class ImsReceipt extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $receipt->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'import_summary' => 'array'];
    }

    /** @return BelongsTo<Lab, $this> */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ImsReceiptLine::class)->orderBy('line_no');
    }

    /**
     * How many other, not-cancelled documents of this branch were filled from the warehouse PDF
     * (the importer is the only thing that sets `import_summary`) and already have at least one
     * confirmed line. Confirming another PDF's closing balance on top of those would count the
     * same stock twice, so the UI warns when this is above zero. Information only.
     */
    public static function confirmedPdfImportCount(int $labId, ?int $exceptId = null): int
    {
        return static::query()
            ->where('lab_id', $labId)
            ->whereNotNull('import_summary')
            ->where('status', '!=', 'CANCELLED')
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereHas('lines', fn ($q) => $q->whereNotNull('ims_lot_id'))
            ->count();
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }
}
