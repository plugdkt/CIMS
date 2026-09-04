<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** FR-ST-01 / BR-05: BR-05's two checks depend on DB state, so `ReturnService` enforces those itself. */
final class RequisitionReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requisition = $this->route('requisition');

        return $user !== null && $requisition instanceof Requisition && $user->can('return', $requisition);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'container_id' => ['required', 'integer', Rule::exists('containers', 'id')],
            'qty_returned' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'remark' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'container_id.required' => __('requisitions.validation.container_required'),
            'qty_returned.required' => __('requisitions.validation.qty_returned_required'),
            'qty_returned.gt' => __('requisitions.validation.qty_returned_gt'),
            'unit_id.required' => __('requisitions.validation.unit_required'),
        ];
    }
}
