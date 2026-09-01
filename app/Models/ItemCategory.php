<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_th', 'is_chemical'])]
class ItemCategory extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_chemical' => 'boolean',
        ];
    }
}
