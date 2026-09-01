<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_th', 'dimension', 'factor_to_base', 'is_base', 'sort_order'])]
class Unit extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'factor_to_base' => 'decimal:12',
            'is_base' => 'boolean',
        ];
    }
}
