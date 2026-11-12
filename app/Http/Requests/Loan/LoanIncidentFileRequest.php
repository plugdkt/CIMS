<?php

declare(strict_types=1);

namespace App\Http\Requests\Loan;

use App\Models\LoanIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /loans/incidents/{loan_incident}/files (step 4, FR-LN-23/24): a photo of the item (PHOTO: images only) or the
 * outside letter / memo (LOAN_DOC: PDF or an image of it). SEC-FU-01/02 like every upload: extension allow-list,
 * real MIME checked by finfo (`mimes:`), size capped by config; the stored name is a fresh UUID
 * (AttachmentUploadService), never the client's.
 */
final class LoanIncidentFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $incident = $this->route('loan_incident');

        return $this->user() !== null && $incident instanceof LoanIncident && $this->user()->can('attachFile', $incident);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKb = (int) (config('attachments.max_size_bytes') / 1024);
        $extensions = $this->input('doc_type') === 'LOAN_DOC' ? 'pdf,jpg,jpeg,png' : 'jpg,jpeg,png';

        return [
            'doc_type' => ['required', Rule::in(LoanIncident::FILE_TYPES)],
            'file' => ['required', 'file', "mimes:{$extensions}", "max:{$maxKb}"],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'doc_type.required' => __('loans.error.incident_file_type'),
            'doc_type.in' => __('loans.error.incident_file_type'),
            'file.required' => __('attachments.validation.file_required'),
            'file.mimes' => __('loans.error.incident_file_mime'),
            'file.max' => __('attachments.validation.file_size'),
        ];
    }
}
