<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** ITM-2610-052: the warehouse card's overview page; anyone holding a warehouse-side ability may open it. */
final class WarehouseOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-warehouse') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
