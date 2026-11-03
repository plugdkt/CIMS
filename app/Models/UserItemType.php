<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ITM-2610-052 phase 3: one kind of stock a warehouse manager is responsible for (composite key
 * user_id + item_type, no id and no route key — never addressed by URL).
 *
 * @property int $user_id
 * @property string $item_type
 */
#[Fillable(['user_id', 'item_type'])]
class UserItemType extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
