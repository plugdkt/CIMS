<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-RQ-09: "สแกน barcode" — the container is looked up by its barcode, not picked from a
 * dropdown of ids. BR-04's conditional remark/LAB_MANAGER-approval requirement depends on
 * computing the line's cumulative overage against DB state, which a static rule set can't
 * express — `IssueService` enforces that itself and the controller translates its
 * exception into a form error.
 */
final class RequisitionIssueRequest extends FormRequest
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
            'barcode' => ['required', 'string', Rule::exists('containers', 'barcode')],
            'qty_issued' => ['required', 'numeric', 'gt:0'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'remark' => ['nullable', 'string', 'max:500'],
            'overage_approved_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            // FR-RQ-11: exactly one receiver-identity channel — a drawn signature or an OTP.
            'signature_image' => ['required_without:otp_code', 'nullable', 'string', 'starts_with:data:image/png;base64,'],
            'otp_code' => ['required_without:signature_image', 'nullable', 'digits:6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required' => __('requisitions.validation.barcode_required'),
            'barcode.exists' => __('requisitions.validation.barcode_not_found'),
            'qty_issued.required' => __('requisitions.validation.qty_issued_required'),
            'qty_issued.gt' => __('requisitions.validation.qty_issued_gt'),
            'unit_id.required' => __('requisitions.validation.unit_required'),
            'signature_image.required_without' => __('requisitions.validation.signature_or_otp_required'),
            'otp_code.required_without' => __('requisitions.validation.signature_or_otp_required'),
            'otp_code.digits' => __('requisitions.validation.otp_code_digits'),
        ];
    }
}
