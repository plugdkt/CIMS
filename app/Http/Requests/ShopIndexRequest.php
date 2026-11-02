<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use App\Domain\Shared\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ShopIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Requisition::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer'],
            // ITM-2610-052: which home card the shopper came from (chemicals / consumables).
            'type' => ['nullable', Rule::in(ItemType::all())],
        ];
    }
}
