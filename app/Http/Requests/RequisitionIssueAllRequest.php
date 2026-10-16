<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Dispense everything in one click": one quantity/unit per requisition line plus a single
 * receiver confirmation shared by all of them. A line left blank (or 0) is skipped.
 * BR-04's conditional remark/override is still enforced in `IssueService` per line.
 */
final class RequisitionIssueAllRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $requisition = $this->route('requisition');

        return $user !== null && $requisition instanceof Requisition && $user->can('issue', $requisition);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_id' => ['required_with:lines.*.qty', 'nullable', 'integer', Rule::exists('units', 'id')],
            'remark' => ['nullable', 'string', 'max:500'],
            'overage_approved_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'signature_image' => ['required_without:otp_code', 'nullable', 'string', 'starts_with:data:image/png;base64,'],
            'otp_code' => ['required_without:signature_image', 'nullable', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.*.qty.numeric' => __('requisitions.validation.qty_issued_required'),
            'lines.*.unit_id.required_with' => __('requisitions.validation.unit_required'),
            'signature_image.required_without' => __('requisitions.validation.signature_or_otp_required'),
            'otp_code.required_without' => __('requisitions.validation.signature_or_otp_required'),
            'otp_code.digits' => __('requisitions.validation.otp_code_digits'),
        ];
    }
}
