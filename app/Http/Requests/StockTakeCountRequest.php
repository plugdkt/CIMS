<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StockTake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-ST-03: "สแกน barcode → กรอกยอดนับจริง → บันทึก" — a container barcode, not a line id. */
final class StockTakeCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $stockTake = $this->route('stock_take');

        return $user !== null && $stockTake instanceof StockTake && $user->can('count', $stockTake);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', Rule::exists('containers', 'barcode')],
            'counted_qty' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required' => __('stock_takes.validation.barcode_required'),
            'barcode.exists' => __('stock_takes.validation.barcode_not_found'),
            'counted_qty.required' => __('stock_takes.validation.counted_qty_required'),
        ];
    }
}
