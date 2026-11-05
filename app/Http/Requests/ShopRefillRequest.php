<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;

/** ITM-2610-062: "ask for the working stock to be refilled" — no input, the item comes from the URL; the gate is ItemPolicy::requestRefill. */
final class ShopRefillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item instanceof Item && $this->user()?->can('requestRefill', $item) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
