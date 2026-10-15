<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
