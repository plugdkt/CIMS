<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['prefix', 'fiscal_year', 'last_number'])]
class DocumentCounter extends Model
{
    public $timestamps = false;
}
