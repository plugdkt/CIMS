<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StockTake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-ST-02: which lab, counted as of which date. */
final class StockTakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', StockTake::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lab_id' => ['required', 'integer', Rule::exists('labs', 'id')],
            'count_date' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lab_id.required' => __('stock_takes.validation.lab_required'),
            'count_date.required' => __('stock_takes.validation.count_date_required'),
        ];
    }
}
