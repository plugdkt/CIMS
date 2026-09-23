<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Requisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * User-requested 2026-09-23: "ให้ระบบแนะนำ/จ่ายจากหลายขวดในคลิกเดียว" — the auto-allocated,
 * multi-container twin of {@see RequisitionIssueRequest}. No `barcode` (the containers are
 * chosen automatically, in FEFO order, by the controller) — otherwise identical rules,
 * including BR-04's conditional remark/override (still enforced in `IssueService` itself,
 * same reasoning as the manual form).
 */
final class RequisitionAutoIssueRequest extends FormRequest
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
            'qty_issued.required' => __('requisitions.validation.qty_issued_required'),
            'qty_issued.gt' => __('requisitions.validation.qty_issued_gt'),
            'unit_id.required' => __('requisitions.validation.unit_required'),
            'signature_image.required_without' => __('requisitions.validation.signature_or_otp_required'),
            'otp_code.required_without' => __('requisitions.validation.signature_or_otp_required'),
            'otp_code.digits' => __('requisitions.validation.otp_code_digits'),
        ];
    }
}
