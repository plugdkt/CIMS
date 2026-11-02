<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\ItemType;
use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The basket page: which kind of basket to show (chemical / consumable). */
final class ShopCartPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Requisition::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(ItemType::all())],
        ];
    }
}
